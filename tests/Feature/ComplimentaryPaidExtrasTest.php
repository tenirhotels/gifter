<?php

namespace Tests\Feature;

use App\Enums\BatchStatus;
use App\Enums\CertificateStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\CertificateBatches\Pages\ViewCertificateBatch;
use App\Filament\Resources\Certificates\Pages\CreateCertificate;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Filament\Resources\Certificates\RelationManagers\ItemsRelationManager;
use App\Models\Certificate;
use App\Models\CertificateBatch;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Service;
use App\Models\User;
use App\Services\CertificateBatchManager;
use App\Services\CertificateManager;
use App\Services\PaymentManager;
use App\Services\ServicePricing;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Платные доп. услуги в бесплатном сертификате: счёт на платную часть, расход — только на бесплатную. */
class ComplimentaryPaidExtrasTest extends TestCase
{
    use RefreshDatabase;

    private Package $free;

    private Service $spa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $pricing = app(ServicePricing::class);
        $dinner = Service::factory()->create(['name' => 'Ужин']);
        $this->spa = Service::factory()->create(['name' => 'Спа']);
        $pricing->addPrice($dinner, '15000', Carbon::parse('2026-01-01'), cost: '6000');
        $pricing->addPrice($this->spa, '20000', Carbon::parse('2026-01-01'), cost: '8000');
        $this->free = Package::create(['name' => 'Блогер', 'validity_value' => 1, 'validity_unit' => 'months', 'is_complimentary' => true, 'complimentary_purpose' => 'influencers']);
        $this->free->items()->create(['service_id' => $dinner->id, 'quantity' => 2]);
    }

    private function draft(?Customer $customer = null): Certificate
    {
        return app(CertificateManager::class)->createDraft([
            'package_id' => $this->free->id, 'complimentary_reason' => 'Блогер', 'customer_id' => $customer?->id,
        ]);
    }

    public function test_free_certificate_without_paid_extras_behaves_as_before(): void
    {
        $certificate = $this->draft();

        $this->assertSame(['30000.00', '0.00', '12000.00'], [$certificate->total_amount, $certificate->payable_amount, $certificate->marketing_cost]);
        $this->assertFalse($certificate->hasPayablePart());
        $this->assertFalse($certificate->items()->where('paid_by_customer', true)->exists());

        app(CertificateManager::class)->approveComplimentary($certificate);
        $this->assertTrue($certificate->refresh()->isReadyToIssue());
        $this->expectException(DomainRuleViolation::class);
        app(PaymentManager::class)->invoice($certificate, PaymentMethod::KaspiInvoice);
    }

    public function test_paid_extra_splits_payable_and_marketing_cost_and_needs_customer(): void
    {
        $certificate = $this->draft();
        $manager = app(CertificateManager::class);

        try {
            $manager->addItem($certificate, $this->spa, 1, paid: true);
            $this->fail('Платная услуга без заказчика');
        } catch (DomainRuleViolation $e) {
            $this->assertStringContainsString('укажите заказчика', $e->getMessage());
        }

        $customer = Customer::factory()->create();
        $certificate = $this->draft($customer);
        $manager->addItem($certificate, $this->spa, 1, paid: true);
        $free = $manager->addItem($certificate, $this->spa, 1);

        $certificate->refresh();
        $this->assertSame(['70000.00', '20000.00', '20000.00'], [$certificate->total_amount, $certificate->payable_amount, $certificate->marketing_cost],
            'К оплате — платная услуга; расход — себестоимость пакета (12 000) и бесплатной доп. услуги (8 000)');
        $this->assertFalse($free->paid_by_customer);
    }

    public function test_issue_needs_approval_and_payment_in_any_order(): void
    {
        $certificate = $this->draft(Customer::factory()->create());
        $manager = app(CertificateManager::class);
        $manager->addItem($certificate, $this->spa, 1, paid: true);
        $payments = app(PaymentManager::class);

        $manager->approveComplimentary($certificate);
        $this->assertFalse($certificate->refresh()->isReadyToIssue(), 'Согласован, но платная часть не оплачена');
        try {
            $manager->issue($certificate);
            $this->fail('Выпуск без оплаты платной части');
        } catch (DomainRuleViolation $e) {
            $this->assertStringContainsString('Платная часть оплачена не полностью', $e->getMessage());
        }

        $payment = $payments->invoice($certificate, PaymentMethod::KaspiInvoice);
        $this->assertSame('20000.00', (string) $payment->amount, 'Счёт — только на платную часть');
        $payments->markPaid($payment);

        $this->assertTrue($certificate->refresh()->isReadyToIssue());
        $manager->issue($certificate);
        $this->assertSame(CertificateStatus::Active, $certificate->refresh()->status);
    }

    public function test_payment_without_approval_does_not_issue(): void
    {
        $certificate = $this->draft(Customer::factory()->create());
        app(CertificateManager::class)->addItem($certificate, $this->spa, 1, paid: true);
        $payments = app(PaymentManager::class);
        $payments->markPaid($payments->invoice($certificate, PaymentMethod::KaspiInvoice));

        $this->assertFalse($certificate->refresh()->isReadyToIssue());
        $this->expectException(DomainRuleViolation::class);
        app(CertificateManager::class)->issue($certificate);
    }

    public function test_toggle_is_limited_to_draft_before_approval_and_invoice_and_is_logged(): void
    {
        $certificate = $this->draft(Customer::factory()->create());
        $manager = app(CertificateManager::class);
        $extra = $manager->addItem($certificate, $this->spa, 1);

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->assertActionHasLabel(TestAction::make('togglePaid')->table($extra), 'Сделать платной')
            ->callAction(TestAction::make('togglePaid')->table($extra));

        $this->assertTrue($extra->refresh()->paid_by_customer);
        $this->assertSame('20000.00', $certificate->refresh()->payable_amount);
        $this->assertTrue($certificate->history()->where('description', 'like', '%Спа × 1: платная, оплачивает заказчик%')->exists());

        $packageItem = $certificate->items()->where('from_package', true)->firstOrFail();
        try {
            $manager->setItemPaid($packageItem, true);
            $this->fail('Услуга бесплатного пакета платной не бывает');
        } catch (DomainRuleViolation) {
        }

        app(PaymentManager::class)->invoice($certificate, PaymentMethod::KaspiInvoice);
        $this->expectException(DomainRuleViolation::class);
        $manager->setItemPaid($extra, false);
    }

    public function test_sale_certificate_is_paid_entirely(): void
    {
        $sale = Package::create(['name' => 'Продажа', 'validity_value' => 1, 'validity_unit' => 'months']);
        $sale->items()->create(['service_id' => $this->spa->id, 'quantity' => 1]);
        $certificate = app(CertificateManager::class)->createDraft(['package_id' => $sale->id, 'customer_id' => Customer::factory()->create()->id]);

        $this->assertSame(['20000.00', '20000.00', '0.00'], [$certificate->total_amount, $certificate->payable_amount, $certificate->marketing_cost]);
        $this->assertTrue($certificate->items()->sole()->paid_by_customer);
    }

    public function test_wizard_summary_shows_real_amount_to_pay(): void
    {
        $customer = Customer::factory()->create();

        $page = Livewire::test(CreateCertificate::class)
            ->fillForm(['package_id' => $this->free->id, 'complimentary_reason' => 'Блогер', 'complimentary_purpose' => 'influencers']);
        $page->assertSeeHtml('gf-summary-total')->assertSee('К оплате')->assertSee('0 ₸')->assertSee('Бесплатно по прайсу');

        $page->fillForm(['extras' => [['service_id' => $this->spa->id, 'quantity' => 1, 'paid' => true]], 'customer_id' => $customer->id])
            ->assertSee('20 000')->assertSee('Платно')->assertSee('(платно)');

        $page->call('create')->assertHasNoFormErrors();
        $certificate = Certificate::firstOrFail();
        $this->assertSame('20000.00', $certificate->payable_amount);
        $this->assertSame(1, $certificate->payments()->count(), 'Счёт выставлен на платную часть');
        $this->assertSame('20000.00', (string) $certificate->payments()->sole()->amount);
    }

    public function test_wizard_requires_customer_for_paid_extra(): void
    {
        Livewire::test(CreateCertificate::class)
            ->fillForm(['package_id' => $this->free->id, 'complimentary_reason' => 'Блогер', 'complimentary_purpose' => 'influencers',
                'extras' => [['service_id' => $this->spa->id, 'quantity' => 1, 'paid' => true]]])
            ->call('create')
            ->assertHasFormErrors(['customer_id']);
    }

    public function test_free_group_with_paid_extra_one_invoice_and_issue_after_both(): void
    {
        $customer = Customer::factory()->create();
        $batches = app(CertificateBatchManager::class);
        $batch = $batches->create($this->free, 2, [
            'customer_id' => $customer->id, 'complimentary_reason' => 'Блогеры', 'complimentary_purpose' => 'influencers',
            'extras' => [['service_id' => $this->spa->id, 'quantity' => 1, 'paid' => true]],
        ]);

        $this->assertEquals(40000, $batch->certificates()->sum('payable_amount'));

        $batches->invoice($batch, PaymentMethod::KaspiInvoice);
        $this->assertSame('40000.00', $batches->invoiceTotal($batch), 'Один счёт на всю платную часть группы');

        $batches->approve($batch);
        $this->assertFalse(CertificateBatch::query()->whereKey($batch->id)->readyToIssue()->exists(), 'Согласована, но не оплачена');
        try {
            $batches->issue($batch);
            $this->fail('Выпуск группы без оплаты');
        } catch (DomainRuleViolation) {
        }

        $batches->markPaid($batch);
        $this->assertTrue(CertificateBatch::query()->whereKey($batch->id)->readyToIssue()->exists());
        $batches->issue($batch);
        $this->assertSame(BatchStatus::Issued, $batch->refresh()->status);
    }

    public function test_free_group_without_paid_extras_cannot_be_invoiced_and_buttons_follow(): void
    {
        $batches = app(CertificateBatchManager::class);
        $free = $batches->create($this->free, 2, ['complimentary_reason' => 'Блогеры', 'complimentary_purpose' => 'influencers']);
        Livewire::test(ViewCertificateBatch::class, ['record' => $free->id])->assertActionHidden('invoiceBatch');
        try {
            $batches->invoice($free, PaymentMethod::KaspiInvoice);
            $this->fail('Счёт на полностью бесплатную группу');
        } catch (DomainRuleViolation) {
        }

        $mixed = $batches->create($this->free, 2, [
            'customer_id' => Customer::factory()->create()->id, 'complimentary_reason' => 'Блогеры', 'complimentary_purpose' => 'influencers',
            'extras' => [['service_id' => $this->spa->id, 'quantity' => 1, 'paid' => true]],
        ]);
        Livewire::test(ViewCertificateBatch::class, ['record' => $mixed->id])
            ->assertActionVisible('invoiceBatch')->assertSee('К оплате заказчиком')->assertSee('40 000');

        $this->expectException(DomainRuleViolation::class);
        $batches->create($this->free, 2, ['complimentary_reason' => 'Блогеры', 'complimentary_purpose' => 'influencers',
            'extras' => [['service_id' => $this->spa->id, 'quantity' => 1, 'paid' => true]]]);
    }
}
