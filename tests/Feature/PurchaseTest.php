<?php

namespace Tests\Feature;

use App\Enums\BatchStatus;
use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\CertificateBatches\Pages\ViewCertificateBatch;
use App\Filament\Resources\Certificates\Pages\CreateCertificate;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Filament\Resources\Certificates\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Certificates\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Recipients\Pages\EditRecipient;
use App\Models\Certificate;
use App\Models\CertificateBatch;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Recipient;
use App\Models\Service;
use App\Models\User;
use App\Services\CertificateBatchManager;
use App\Services\CertificateManager;
use App\Services\PaymentManager;
use App\Services\RefundManager;
use App\Services\ServicePricing;
use App\Support\DashboardQueries;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Замечание 25.10.2026: несколько сертификатов в одной продаже — группа с одним счётом. */
class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    private Service $extra;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $pricing = app(ServicePricing::class);
        $room = Service::factory()->create(['name' => 'Номер']);
        $pricing->addPrice($room, '50000', Carbon::parse('2026-01-01'));
        $this->extra = Service::factory()->create(['name' => 'Ужин']);
        $pricing->addPrice($this->extra, '10000', Carbon::parse('2026-01-01'));
        $this->package = Package::create(['name' => 'Уикенд', 'validity_value' => 6, 'validity_unit' => 'months']);
        $this->package->items()->create(['service_id' => $room->id, 'quantity' => 1]);
        $this->customer = Customer::factory()->create();
    }

    private function as(Role ...$roles): User
    {
        $user = User::factory()->create(['roles' => $roles]);
        $this->actingAs($user);

        return $user;
    }

    private function purchase(int $quantity = 2): CertificateBatch
    {
        $batches = app(CertificateBatchManager::class);
        $batch = $batches->create($this->package, $quantity, ['customer_id' => $this->customer->id]);

        return $batches->invoice($batch, PaymentMethod::KaspiInvoice, 'K-1');
    }

    public function test_wizard_with_two_certificates_creates_purchase_with_one_invoice(): void
    {
        $this->as(Role::Sales);
        Livewire::test(CreateCertificate::class)
            ->assertSee('Оформление сертификата')->assertSee('Сводка')->assertSee('Выберите пакет')->assertDontSee('Продажа сертификата')
            ->fillForm([
                'package_id' => $this->package->id, 'certificate_count' => 2, 'customer_id' => $this->customer->id,
                'extras' => [['service_id' => $this->extra->id, 'quantity' => 1]],
                'create_invoice' => true, 'payment_method' => 'kaspi_invoice', 'invoice_number' => 'K-77',
            ])
            ->call('create')->assertHasNoFormErrors()
            ->assertRedirect('/admin/certificate-batches/1');

        $batch = CertificateBatch::firstOrFail();
        $this->assertSame(CertificateKind::Sale, $batch->kind);
        $this->assertSame('Черновик: счёт и оплата', $batch->statusLabel());
        $this->assertSame(['60000.00', '60000.00'], $batch->certificates()->pluck('total_amount')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(2, $batch->certificates()->first()->items()->count());
        $this->assertSame(2, $batch->payments()->where('invoice_number', 'K-77')->where('status', PaymentStatus::Pending)->count());
        $this->assertSame('120000.00', app(CertificateBatchManager::class)->invoiceTotal($batch));
    }

    public function test_quote_table_groups_package_and_extra_services(): void
    {
        $this->as(Role::Sales);
        Livewire::test(CreateCertificate::class)
            ->set('data.package_id', $this->package->id)
            ->set('data.extras', [['service_id' => $this->extra->id, 'quantity' => 1]])
            ->set('data.certificate_count', 2)
            ->assertSeeHtml('class="gf-quote"')
            ->assertSee('Пакет «Уикенд»')->assertSee('Доп. услуги')
            ->assertSee('За 1 сертификат')->assertSee('Итого за 2 шт.')->assertSee('120 000 ₸');
    }

    public function test_edit_pages_have_no_big_heading(): void
    {
        $this->as(Role::SalesManager);
        $recipient = Recipient::factory()->create(['last_name' => 'Антонов', 'first_name' => 'Юра']);

        Livewire::test(EditRecipient::class, ['record' => $recipient->id])
            ->assertDontSeeHtml('fi-header-heading')->assertSet('data.last_name', 'Антонов');
    }

    public function test_one_certificate_is_ordinary_sale_without_purchase(): void
    {
        $this->as(Role::Sales);
        Livewire::test(CreateCertificate::class)
            ->fillForm(['package_id' => $this->package->id, 'customer_id' => $this->customer->id, 'create_invoice' => false])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame(0, CertificateBatch::count());
        $this->assertNull(Certificate::firstOrFail()->batch_id);
    }

    public function test_mark_paid_on_purchase_pays_all_and_issue_activates_all(): void
    {
        $this->as(Role::Sales);
        $batch = $this->purchase();

        Livewire::test(ViewCertificateBatch::class, ['record' => $batch->id])->assertActionHidden('issueBatch');

        Livewire::test(ViewCertificateBatch::class, ['record' => $batch->id])->callAction('markBatchPaid', ['paid_at' => now()]);
        $this->assertSame(2, $batch->payments()->where('status', PaymentStatus::Paid)->count());
        $this->assertSame(1, (new DashboardQueries(auth()->user()))->purchasesReadyToIssue()->count());

        Livewire::test(ViewCertificateBatch::class, ['record' => $batch->id])->assertActionVisible('issueBatch')->callAction('issueBatch');
        $this->assertSame(BatchStatus::Issued, $batch->refresh()->status);
        $this->assertSame(2, $batch->certificates()->where('status', CertificateStatus::Active)->count());
        $this->assertSame(0, (new DashboardQueries(auth()->user()))->purchasesReadyToIssue()->count());
    }

    public function test_issue_requires_payment_in_service(): void
    {
        $batch = $this->purchase();

        $this->expectException(DomainRuleViolation::class);
        app(CertificateBatchManager::class)->issue($batch);
    }

    public function test_per_certificate_invoice_payment_and_composition_are_forbidden_in_draft(): void
    {
        $this->as(Role::Sales);
        $batch = app(CertificateBatchManager::class)->create($this->package, 2, ['customer_id' => $this->customer->id]);
        $certificate = $batch->certificates()->first();

        foreach ([
            fn () => app(PaymentManager::class)->invoice($certificate, PaymentMethod::KaspiInvoice),
            fn () => app(CertificateManager::class)->addItem($certificate, $this->extra, 1),
            fn () => app(CertificateManager::class)->issue($certificate),
            fn () => app(CertificateManager::class)->updateDraft($certificate, ['customer_id' => Customer::factory()->create()->id]),
        ] as $i => $forbidden) {
            try {
                $forbidden();
                $this->fail("Действие {$i} с сертификатом группы");
            } catch (DomainRuleViolation) {
            }
        }

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->assertActionHidden('issue')->assertActionVisible('openBatch');
        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->assertActionHidden(TestAction::make('addItem')->table());
        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->assertActionHidden(TestAction::make('invoice')->table());
    }

    public function test_cancel_invoice_cancels_all_lines(): void
    {
        $batch = $this->purchase();
        app(CertificateBatchManager::class)->cancelInvoice($batch, 'Ошибка в сумме');

        $this->assertSame(2, $batch->payments()->where('status', PaymentStatus::Cancelled)->count());
        app(CertificateBatchManager::class)->invoice($batch, PaymentMethod::HalykInvoice);
        $this->assertSame(2, $batch->payments()->where('status', PaymentStatus::Pending)->count());
    }

    public function test_finance_reconciles_whole_purchase_invoice_from_one_line(): void
    {
        $batch = $this->purchase(3);
        app(CertificateBatchManager::class)->markPaid($batch);

        $this->as(Role::Finance);
        $line = $batch->payments()->firstOrFail();
        Livewire::test(ListPayments::class)
            ->assertSee('Группа №'.$batch->id.' (из 3)')
            ->callAction(TestAction::make('reconcile')->table($line));
        $this->assertSame(3, $batch->payments()->whereNotNull('reconciled_at')->count());
    }

    public function test_bulk_reconcile_skips_lines_reconciled_with_siblings(): void
    {
        $batch = $this->purchase();
        app(CertificateBatchManager::class)->markPaid($batch);

        $this->as(Role::Finance);
        Livewire::test(ListPayments::class)
            ->callTableBulkAction('reconcileSelected', $batch->payments()->get())
            ->assertNotified('Сверено оплат: 1');
        $this->assertSame(2, Payment::whereNotNull('reconciled_at')->count());
    }

    public function test_bank_transfer_purchase_is_marked_by_finance_only(): void
    {
        $this->as(Role::Sales);
        $batches = app(CertificateBatchManager::class);
        $batch = $batches->invoice($batches->create($this->package, 2, ['customer_id' => $this->customer->id]), PaymentMethod::BankTransfer);

        Livewire::test(ViewCertificateBatch::class, ['record' => $batch->id])->assertActionHidden('markBatchPaid');

        $this->as(Role::Finance);
        Livewire::test(ViewCertificateBatch::class, ['record' => $batch->id])->callAction('markBatchPaid', ['paid_at' => now()]);
        $this->assertSame(2, $batch->payments()->where('status', PaymentStatus::Paid)->whereNotNull('reconciled_at')->count());
    }

    public function test_after_issue_refund_of_one_certificate_does_not_touch_others(): void
    {
        $batches = app(CertificateBatchManager::class);
        $batch = $this->purchase();
        $batches->markPaid($batch);
        $batches->issue($batch);
        [$first, $second] = $batch->certificates()->orderBy('id')->get()->all();

        $refunds = app(RefundManager::class);
        $refunds->approve($refunds->request($first, '50000', 'Гость не приедет'));

        $this->assertSame(CertificateStatus::Cancelled, $first->refresh()->status);
        $this->assertSame(CertificateStatus::Active, $second->refresh()->status);
        $this->assertSame(BatchStatus::Issued, $batch->refresh()->status);
    }
}
