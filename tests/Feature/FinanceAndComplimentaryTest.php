<?php

namespace Tests\Feature;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\PaymentMethod;
use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Certificates\Pages\CreateCertificate;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Filament\Resources\Certificates\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Recipient;
use App\Models\Service;
use App\Models\User;
use App\Services\CertificateManager;
use App\Services\PaymentManager;
use App\Services\RefundManager;
use App\Services\ServicePricing;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceAndComplimentaryTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $this->service = Service::factory()->create();
        app(ServicePricing::class)->addPrice($this->service, '25000', Carbon::parse('2026-01-01'));
    }

    private function as(Role ...$roles): User
    {
        $user = User::factory()->create(['roles' => $roles]);
        $this->actingAs($user);

        return $user;
    }

    private function draft(array $data = []): Certificate
    {
        $certificate = app(CertificateManager::class)->createDraft($data + [
            'customer_id' => Customer::factory()->create()->id,
            'recipient_id' => Recipient::factory()->create()->id,
        ]);
        app(CertificateManager::class)->addItem($certificate, $this->service, 2);

        return $certificate->refresh();
    }

    private function issuedPaid(): Certificate
    {
        $certificate = $this->draft();
        app(PaymentManager::class)->markPaid(app(PaymentManager::class)->invoice($certificate, PaymentMethod::KaspiInvoice));
        app(CertificateManager::class)->issue($certificate);

        return $certificate->refresh();
    }

    // --- Бесплатные сертификаты ---

    public function test_complimentary_is_issued_without_payment_and_keeps_price_value(): void
    {
        $certificate = $this->draft(['kind' => CertificateKind::Complimentary, 'complimentary_reason' => 'Блогер, коллаборация', 'customer_id' => null]);

        app(CertificateManager::class)->approveComplimentary($certificate);
        app(CertificateManager::class)->issue($certificate);

        $this->assertSame(CertificateStatus::Active, $certificate->status);
        $this->assertSame('50000.00', (string) $certificate->total_amount);
        $this->assertNull($certificate->customer_id);
    }

    public function test_complimentary_requires_reason_and_cannot_be_invoiced(): void
    {
        try {
            $this->draft(['kind' => CertificateKind::Complimentary]);
            $this->fail('Бесплатный без основания');
        } catch (DomainRuleViolation) {
        }

        $certificate = $this->draft(['kind' => CertificateKind::Complimentary, 'complimentary_reason' => 'Розыгрыш']);
        $this->expectException(DomainRuleViolation::class);
        app(PaymentManager::class)->invoice($certificate, PaymentMethod::KaspiInvoice);
    }

    public function test_sale_requires_customer(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->draft(['customer_id' => null]);
    }

    public function test_only_sales_manager_sees_kind_choice_on_create(): void
    {
        $recipient = Recipient::factory()->create();

        $this->as(Role::Sales);
        Livewire::test(CreateCertificate::class)->assertFormFieldHidden('kind');

        $this->as(Role::SalesManager);
        Livewire::test(CreateCertificate::class)
            ->fillForm(['kind' => 'complimentary', 'complimentary_reason' => 'Партнёр', 'recipient_id' => $recipient->id])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertTrue(Certificate::firstOrFail()->isComplimentary());
    }

    // --- Видимость продавца ---

    public function test_sales_sees_only_own_certificates_manager_sees_all(): void
    {
        $seller = $this->as(Role::Sales);
        $own = $this->draft();
        $this->as(Role::Sales);
        $foreign = $this->draft();

        $this->actingAs($seller);
        $this->get("/admin/certificates/{$own->id}")->assertOk();
        $this->get("/admin/certificates/{$foreign->id}")->assertNotFound();
        $this->get('/admin/certificates')->assertSee($own->number)->assertDontSee($foreign->number);

        $this->as(Role::SalesManager);
        $this->get('/admin/certificates')->assertSee($own->number)->assertSee($foreign->number);
        $this->get("/admin/certificates/{$foreign->id}")->assertOk();
    }

    // --- Оплата перечислением и сверка ---

    public function test_bank_transfer_is_confirmed_only_by_finance_and_counts_as_reconciled(): void
    {
        $this->as(Role::Sales);
        $certificate = $this->draft();
        $payment = app(PaymentManager::class)->invoice($certificate, PaymentMethod::BankTransfer, invoiceNumber: 'Счёт 15');

        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->assertActionHidden(TestAction::make('markPaid')->table($payment));

        $this->as(Role::Finance);
        Livewire::test(ListPayments::class)
            ->assertCanSeeTableRecords([$payment])
            ->callAction(TestAction::make('receiveTransfer')->table($payment), ['paid_at' => now()]);

        $this->assertTrue($payment->refresh()->isReconciled());
        $this->assertTrue($certificate->refresh()->isFullyPaid());
    }

    public function test_kaspi_payment_marked_by_sales_waits_for_finance_reconciliation(): void
    {
        $this->as(Role::Sales);
        $certificate = $this->draft();
        $payment = app(PaymentManager::class)->invoice($certificate, PaymentMethod::KaspiInvoice);
        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->callAction(TestAction::make('markPaid')->table($payment), ['paid_at' => now()])
            ->assertActionHidden(TestAction::make('reconcile')->table($payment));
        $this->assertFalse($payment->refresh()->isReconciled());

        $this->as(Role::Finance);
        Livewire::test(ListPayments::class)
            ->callTableBulkAction('reconcileSelected', [$payment]);
        $this->assertTrue($payment->refresh()->isReconciled());

        $this->expectException(DomainRuleViolation::class);
        app(PaymentManager::class)->reconcile($payment);
    }

    public function test_payment_register_is_hidden_from_sales_and_front_office(): void
    {
        foreach ([Role::Sales, Role::FrontOffice] as $role) {
            $this->as($role);
            $this->get('/admin/payments')->assertForbidden();
            $this->get('/admin/refunds')->assertForbidden();
        }
        $this->as(Role::Finance);
        $this->get('/admin/payments')->assertOk();
        $this->get('/admin/refunds')->assertOk();
    }

    // --- Возврат с аннулированием ---

    public function test_refund_flow_request_approve_pay(): void
    {
        $certificate = $this->issuedPaid();

        $this->as(Role::SalesManager);
        $refund = app(RefundManager::class)->request($certificate, '50000', 'Гость не сможет приехать, согласовано с ГМ');

        try {
            app(CertificateManager::class)->transition($certificate, CertificateStatus::Reserved);
            $this->fail('Сертификат не заблокирован');
        } catch (DomainRuleViolation) {
        }

        $this->as(Role::Finance);
        Livewire::test(ListRefunds::class)->callAction(TestAction::make('approveRefund')->table($refund), ['comment' => 'ОК']);
        $this->assertSame(CertificateStatus::Cancelled, $certificate->refresh()->status);
        $this->assertSame(RefundStatus::Approved, $refund->refresh()->status);

        Livewire::test(ListRefunds::class)->callAction(TestAction::make('payRefund')->table($refund), ['paid_at' => now(), 'reference' => 'п/п 77']);
        $this->assertSame(RefundStatus::Completed, $refund->refresh()->status);
        $this->assertSame('п/п 77', $refund->payout_reference);
    }

    public function test_rejected_refund_unblocks_certificate(): void
    {
        $certificate = $this->issuedPaid();
        $refund = app(RefundManager::class)->request($certificate, '10000', 'Просьба клиента');

        app(RefundManager::class)->reject($refund, 'Не основание для возврата');

        $this->assertSame(CertificateStatus::Active, $certificate->refresh()->status);
        app(CertificateManager::class)->transition($certificate, CertificateStatus::Reserved);
        $this->assertSame(CertificateStatus::Reserved, $certificate->refresh()->status);
    }

    public function test_refund_cannot_exceed_paid_amount_and_manager_cannot_approve(): void
    {
        $certificate = $this->issuedPaid();

        try {
            app(RefundManager::class)->request($certificate, '50000.01', 'Ошибка');
            $this->fail('Возврат больше оплаты');
        } catch (DomainRuleViolation) {
        }

        $this->as(Role::SalesManager);
        $refund = app(RefundManager::class)->request($certificate, '0', 'Без возврата денег, по договорённости');
        Livewire::test(ListRefunds::class)->assertActionHidden(TestAction::make('approveRefund')->table($refund));

        $this->as(Role::Finance);
        app(RefundManager::class)->approve($refund);
        $this->assertSame(RefundStatus::Completed, $refund->refresh()->status);
    }

    public function test_sales_cannot_request_refund(): void
    {
        $this->as(Role::Sales);
        $certificate = $this->draft();
        app(PaymentManager::class)->markPaid(app(PaymentManager::class)->invoice($certificate, PaymentMethod::KaspiInvoice));
        app(CertificateManager::class)->issue($certificate);

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->assertActionHidden('requestRefund')
            ->assertActionHidden('cancel');
    }
}
