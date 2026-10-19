<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\HistoryEvent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Recipient;
use App\Models\Service;
use App\Models\User;
use App\Services\AdminCorrections;
use App\Services\CertificateManager;
use App\Services\PaymentManager;
use App\Services\RefundManager;
use App\Services\ServicePricing;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class AdminCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $this->service = Service::factory()->create();
        app(ServicePricing::class)->addPrice($this->service, '20000', Carbon::parse('2026-01-01'));
    }

    private function issued(): Certificate
    {
        $certificate = app(CertificateManager::class)->createDraft([
            'package_id' => $this->emptyPackageId(),
            'customer_id' => Customer::factory()->create()->id,
            'recipient_id' => Recipient::factory()->create()->id,
        ]);
        app(CertificateManager::class)->addItem($certificate, $this->service, 1);
        app(PaymentManager::class)->markPaid(app(PaymentManager::class)->invoice($certificate, PaymentMethod::KaspiInvoice));
        app(CertificateManager::class)->issue($certificate);

        return $certificate->refresh();
    }

    public function test_admin_reverts_mistaken_redemption_and_history_is_kept(): void
    {
        $certificate = $this->issued();
        $this->reserve($certificate);
        foreach ([CertificateStatus::InUse, CertificateStatus::Used] as $status) {
            app(CertificateManager::class)->transition($certificate->refresh(), $status);
        }
        $historyBefore = $certificate->history()->count();

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->callAction('adminSetStatus', ['status' => 'in_use', 'reason' => 'Погасили не тот сертификат']);

        $this->assertSame(CertificateStatus::InUse, $certificate->refresh()->status);
        $this->assertSame($historyBefore + 1, $certificate->history()->count());
        $entry = $certificate->history()->first();
        $this->assertSame(HistoryEvent::AdminCorrection, $entry->event);
        $this->assertStringContainsString('Погасили не тот сертификат', $entry->description);
        $this->assertSame('used', $entry->before['status']);
    }

    public function test_admin_restores_cancelled_certificate(): void
    {
        $certificate = app(CertificateManager::class)->createDraft(['package_id' => $this->emptyPackageId(), 'customer_id' => Customer::factory()->create()->id]);
        app(CertificateManager::class)->cancel($certificate, 'ошибка');

        app(AdminCorrections::class)->setStatus($certificate, CertificateStatus::Draft, 'Аннулировали по ошибке');

        $this->assertSame(CertificateStatus::Draft, $certificate->refresh()->status);
        $this->assertNull($certificate->cancelled_at);
    }

    public function test_reason_is_required(): void
    {
        $this->expectException(DomainRuleViolation::class);
        app(AdminCorrections::class)->setStatus($this->issued(), CertificateStatus::Expired, '  ');
    }

    public function test_admin_reopens_mistaken_payment_and_unreconciles(): void
    {
        $certificate = $this->issued();
        $payment = $certificate->payments()->first();
        app(PaymentManager::class)->reconcile($payment);

        Livewire::test(ListPayments::class)
            ->removeTableFilters()
            ->callAction(TestAction::make('adminUnreconcile')->table($payment), ['reason' => 'Сверили не ту выписку']);
        $this->assertFalse($payment->refresh()->isReconciled());

        app(AdminCorrections::class)->reopenPayment($payment, 'Деньги не поступили');
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertNull($payment->paid_at);
    }

    public function test_reopening_cancelled_invoice_cannot_exceed_total(): void
    {
        $certificate = app(CertificateManager::class)->createDraft(['package_id' => $this->emptyPackageId(), 'customer_id' => Customer::factory()->create()->id]);
        app(CertificateManager::class)->addItem($certificate, $this->service, 1);
        $first = app(PaymentManager::class)->invoice($certificate, PaymentMethod::KaspiInvoice);
        app(PaymentManager::class)->cancel($first, 'перевыставим');
        app(PaymentManager::class)->invoice($certificate, PaymentMethod::HalykInvoice);

        $this->expectException(DomainRuleViolation::class);
        app(AdminCorrections::class)->reopenPayment($first, 'ошибка');
    }

    public function test_admin_reopens_approved_refund_and_certificate_status_returns(): void
    {
        $certificate = $this->issued();
        $this->reserve($certificate);
        $refund = app(RefundManager::class)->request($certificate, '20000', 'Просьба гостя');
        app(RefundManager::class)->approve($refund);
        $this->assertSame(CertificateStatus::Cancelled, $certificate->refresh()->status);

        Livewire::test(ListRefunds::class)
            ->callAction(TestAction::make('adminReopenRefund')->table($refund), ['reason' => 'Согласовали не тот запрос']);

        $this->assertSame(RefundStatus::Requested, $refund->refresh()->status);
        $this->assertSame(CertificateStatus::Reserved, $certificate->refresh()->status);
        $this->assertTrue($certificate->hasPendingRefund());
    }

    public function test_corrections_are_hidden_from_other_roles(): void
    {
        $certificate = $this->issued();

        foreach ([Role::SalesManager, Role::SalesDirector, Role::Finance] as $role) {
            $this->actingAs(User::factory()->create(['roles' => [$role]]));
            Livewire::test(ViewCertificate::class, ['record' => $certificate->id])->assertActionHidden('adminSetStatus');
        }
        $this->actingAs(User::factory()->create(['roles' => [Role::Finance]]));
        Livewire::test(ListPayments::class)
            ->removeTableFilters()
            ->assertActionHidden(TestAction::make('adminReopenPayment')->table($certificate->payments()->first()));
    }

    public function test_admin_can_fix_old_unused_price(): void
    {
        $old = $this->service->prices()->first();
        Carbon::setTestNow('2026-12-01 10:00:00');

        app(ServicePricing::class)->updatePrice($old, '21000', Carbon::parse('2026-01-01'));

        $this->assertSame('21000.00', (string) $old->refresh()->price);
    }
}
