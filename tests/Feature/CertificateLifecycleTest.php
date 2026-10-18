<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\HistoryEvent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Recipient;
use App\Models\Service;
use App\Models\User;
use App\Services\CertificateManager;
use App\Services\PaymentManager;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CertificateLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private CertificateManager $certificates;

    private PaymentManager $payments;

    private ServicePricing $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $this->certificates = app(CertificateManager::class);
        $this->payments = app(PaymentManager::class);
        $this->pricing = app(ServicePricing::class);
    }

    private function serviceWithPrice(string $name, string $price, string $from = '2026-01-01'): Service
    {
        $service = Service::factory()->create(['name' => $name]);
        $this->pricing->addPrice($service, $price, Carbon::parse($from));

        return $service;
    }

    private function draft(array $overrides = []): Certificate
    {
        return $this->certificates->createDraft($overrides + [
            'customer_id' => Customer::factory()->create()->id,
            'recipient_id' => Recipient::factory()->create()->id,
        ]);
    }

    private function paidDraft(): Certificate
    {
        $certificate = $this->draft();
        $this->certificates->addItem($certificate, $this->serviceWithPrice('SPA', '40000'), 2);
        $payment = $this->payments->invoice($certificate, PaymentMethod::KaspiInvoice);
        $this->payments->markPaid($payment);

        return $certificate->refresh();
    }

    public function test_draft_gets_number_and_default_validity(): void
    {
        $certificate = $this->draft();

        $this->assertSame('GIF-000001', $certificate->number);
        $this->assertSame(CertificateStatus::Draft, $certificate->status);
        $this->assertSame('2026-10-18', $certificate->sold_at->toDateString());
        $this->assertSame('2027-10-18', $certificate->valid_until->toDateString());
        $this->assertSame(HistoryEvent::Created, $certificate->history()->first()->event);
        $this->assertSame('GIF-000002', $this->draft()->number);
    }

    public function test_yearly_pattern_restarts_sequence_by_sale_date(): void
    {
        config(['gifter.number.pattern' => '{PREFIX}-{YYYY}-{SEQ}']);
        $this->assertSame('GIF-2026-000001', $this->draft()->number);
        $this->assertSame('GIF-2027-000001', $this->draft(['sold_at' => '2027-01-05'])->number);
    }

    public function test_number_pattern_without_year_is_global(): void
    {
        config(['gifter.number.pattern' => '{PREFIX}-{SEQ}']);
        $this->draft();
        $this->assertSame('GIF-000002', $this->draft(['sold_at' => '2027-01-05'])->number);
    }

    public function test_item_price_is_taken_on_sale_date_and_snapshotted(): void
    {
        $spa = $this->serviceWithPrice('SPA', '40000');
        $this->pricing->addPrice($spa, '50000', Carbon::parse('2026-11-01'));

        $certificate = $this->draft();
        $item = $this->certificates->addItem($certificate, $spa, 2);

        $this->assertSame('40000.00', (string) $item->unit_price);
        $this->assertSame('80000.00', (string) $item->line_total);
        $this->assertSame('80000.00', (string) $certificate->refresh()->total_amount);

        $spa->update(['name' => 'SPA Deluxe']);
        $this->assertSame('SPA', $item->refresh()->service_name_snapshot);
    }

    public function test_used_price_version_cannot_be_rewritten(): void
    {
        $spa = $this->serviceWithPrice('SPA', '40000');
        $this->certificates->addItem($this->draft(), $spa, 1);

        $this->expectException(DomainRuleViolation::class);
        $spa->prices()->first()->update(['price' => '1']);
    }

    public function test_new_price_closes_previous_open_version_and_rejects_overlap(): void
    {
        $spa = $this->serviceWithPrice('SPA', '40000');
        $this->pricing->addPrice($spa, '50000', Carbon::parse('2026-07-01'));

        $this->assertSame('2026-06-30', $spa->prices()->where('price', '40000')->first()->valid_until->toDateString());

        $this->expectException(DomainRuleViolation::class);
        $this->pricing->addPrice($spa, '45000', Carbon::parse('2026-07-01'), Carbon::parse('2026-08-01'));
    }

    public function test_package_is_copied_into_items_and_later_changes_do_not_affect_certificate(): void
    {
        $room = $this->serviceWithPrice('Проживание', '150000');
        $breakfast = $this->serviceWithPrice('Завтрак', '8500');
        $package = Package::create(['name' => 'Романтика', 'is_active' => true]);
        $package->items()->create(['service_id' => $room->id, 'quantity' => 2]);
        $package->items()->create(['service_id' => $breakfast->id, 'quantity' => 2]);

        $certificate = $this->draft(['package_id' => $package->id]);
        $package->items()->where('service_id', $breakfast->id)->update(['quantity' => 10]);

        $this->assertSame('317000.00', (string) $certificate->total_amount);
        $this->assertSame([2, 2], $certificate->items()->pluck('quantity')->all());
    }

    public function test_issue_requires_full_payment(): void
    {
        $certificate = $this->draft();
        $this->certificates->addItem($certificate, $this->serviceWithPrice('SPA', '40000'), 1);
        $this->payments->invoice($certificate, PaymentMethod::HalykInvoice);

        $this->expectException(DomainRuleViolation::class);
        $this->certificates->issue($certificate);
    }

    public function test_issue_requires_items(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->certificates->issue($this->draft());
    }

    public function test_full_lifecycle_until_used(): void
    {
        $certificate = $this->paidDraft();
        $this->certificates->issue($certificate);
        $this->assertSame(CertificateStatus::Active, $certificate->status);
        $this->assertNotNull($certificate->issued_at);

        $this->certificates->transition($certificate, CertificateStatus::Reserved);
        $this->certificates->transition($certificate, CertificateStatus::InUse);
        $this->certificates->transition($certificate, CertificateStatus::Used);

        $this->assertSame(CertificateStatus::Used, $certificate->refresh()->status);
        $this->expectException(DomainRuleViolation::class);
        $this->certificates->transition($certificate, CertificateStatus::Active);
    }

    public function test_partial_payments_are_allowed_but_not_over_total(): void
    {
        $certificate = $this->draft();
        $this->certificates->addItem($certificate, $this->serviceWithPrice('SPA', '40000'), 1);

        $first = $this->payments->invoice($certificate, PaymentMethod::KaspiInvoice, '15000');
        $second = $this->payments->invoice($certificate, PaymentMethod::HalykInvoice);
        $this->assertSame('25000.00', (string) $second->amount);

        $this->payments->markPaid($first);
        $this->payments->markPaid($second);
        $this->certificates->issue($certificate);
        $this->assertSame(CertificateStatus::Active, $certificate->status);
    }

    public function test_invoice_over_outstanding_is_rejected(): void
    {
        $certificate = $this->draft();
        $this->certificates->addItem($certificate, $this->serviceWithPrice('SPA', '40000'), 1);

        $this->expectException(DomainRuleViolation::class);
        $this->payments->invoice($certificate, PaymentMethod::KaspiInvoice, '40000.01');
    }

    public function test_composition_is_locked_while_invoice_is_open(): void
    {
        $certificate = $this->draft();
        $spa = $this->serviceWithPrice('SPA', '40000');
        $this->certificates->addItem($certificate, $spa, 1);
        $payment = $this->payments->invoice($certificate, PaymentMethod::KaspiInvoice);

        try {
            $this->certificates->addItem($certificate, $spa, 1);
            $this->fail('Состав изменён при открытом счёте');
        } catch (DomainRuleViolation) {
        }

        $this->payments->cancel($payment, 'Клиент добавил услугу');
        $this->certificates->addItem($certificate, $spa, 1);
        $this->assertSame('80000.00', (string) $certificate->refresh()->total_amount);
        $this->assertSame(PaymentStatus::Cancelled, $payment->refresh()->status);
    }

    public function test_paid_payment_cannot_be_cancelled(): void
    {
        $certificate = $this->paidDraft();

        $this->expectException(DomainRuleViolation::class);
        $this->payments->cancel($certificate->payments()->first(), 'ошибка');
    }

    public function test_issued_certificate_items_cannot_change(): void
    {
        $certificate = $this->paidDraft();
        $this->certificates->issue($certificate);

        $this->expectException(DomainRuleViolation::class);
        $certificate->items()->first()->update(['quantity' => 5]);
    }

    public function test_certificates_and_payments_cannot_be_deleted(): void
    {
        $certificate = $this->paidDraft();

        foreach ([$certificate, $certificate->payments()->first(), $certificate->history()->first()] as $model) {
            try {
                $model->delete();
                $this->fail(class_basename($model).' удалён');
            } catch (DomainRuleViolation) {
            }
        }
        $this->assertDatabaseCount('certificates', 1);
    }

    public function test_history_is_append_only(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->draft()->history()->first()->update(['description' => 'подмена']);
    }

    public function test_cancel_requires_reason_and_closes_lifecycle(): void
    {
        $certificate = $this->paidDraft();
        $this->certificates->issue($certificate);
        $this->certificates->cancel($certificate, 'Возврат по просьбе покупателя');

        $this->assertSame(CertificateStatus::Cancelled, $certificate->status);
        $this->assertSame(HistoryEvent::Cancelled, $certificate->history()->first()->event);
        $this->expectException(DomainRuleViolation::class);
        $this->certificates->transition($certificate, CertificateStatus::Active);
    }

    public function test_validity_change_keeps_old_values_in_history(): void
    {
        $certificate = $this->paidDraft();
        $this->certificates->issue($certificate);
        $this->certificates->changeValidity($certificate, Carbon::parse('2026-10-18'), Carbon::parse('2027-12-31'), 'Продление по акции');

        $entry = $certificate->history()->first();
        $this->assertSame(HistoryEvent::ValidityChanged, $entry->event);
        $this->assertSame('2027-10-18', $entry->before['valid_until']);
        $this->assertSame('2027-12-31', $entry->after['valid_until']);
    }

    public function test_changing_sale_date_in_draft_reprices_items(): void
    {
        $spa = $this->serviceWithPrice('SPA', '40000');
        $this->pricing->addPrice($spa, '50000', Carbon::parse('2026-11-01'));
        $certificate = $this->draft();
        $this->certificates->addItem($certificate, $spa, 1);

        $this->certificates->updateDraft($certificate, ['sold_at' => '2026-11-02']);

        $this->assertSame('50000.00', (string) $certificate->refresh()->total_amount);
        $this->assertSame(HistoryEvent::Repriced, $certificate->history()->first()->event);
    }

    public function test_expire_command_moves_only_overdue_active_certificates(): void
    {
        $overdue = $this->paidDraft();
        $this->certificates->changeValidity($overdue, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'), 'тест');
        $this->certificates->issue($overdue);
        $fresh = $this->paidDraft();
        $this->certificates->issue($fresh);
        $draft = $this->draft(['valid_from' => '2026-01-01', 'valid_until' => '2026-12-31']);

        Carbon::setTestNow('2027-01-01 01:00:00');
        $this->artisan('gifter:expire-certificates')->assertSuccessful();

        $this->assertSame(CertificateStatus::Expired, $overdue->refresh()->status);
        $this->assertSame(CertificateStatus::Active, $fresh->refresh()->status);
        $this->assertSame(CertificateStatus::Draft, $draft->refresh()->status);
    }

    public function test_catalog_changes_are_audited(): void
    {
        $service = Service::factory()->create(['name' => 'Трансфер']);
        $service->update(['unit' => 'поездка']);

        $this->assertDatabaseHas('audit_logs', ['auditable_type' => Service::class, 'auditable_id' => $service->id, 'event' => 'updated']);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => Service::class, 'event' => 'created']);
    }
}
