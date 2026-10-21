<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\HistoryEvent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Mail\EpayPaymentLinkMail;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\CertificateManager;
use App\Services\Epay\EpayClient;
use App\Services\EpayPayments;
use App\Services\PaymentManager;
use App\Services\SecurityAudit;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Оплата картой по ссылке банка: все обращения к банку подменены (Http::fake), настоящих ключей нет. */
class EpayPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const TERMINAL = 'terminal-fictional-0001';

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-15 12:00:00');
        config(['gifter.epay.client_id' => 'fictional-client', 'gifter.epay.client_secret' => 'fictional-secret', 'gifter.epay.terminal' => self::TERMINAL, 'gifter.epay.env' => 'test']);
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $service = Service::factory()->create(['name' => 'Ужин на двоих']);
        app(ServicePricing::class)->addPrice($service, '50000', Carbon::parse('2026-01-01'));
        $this->package = Package::create(['name' => 'Романтический вечер', 'validity_value' => 3, 'validity_unit' => 'months']);
        $this->package->items()->create(['service_id' => $service->id, 'quantity' => 1]);
    }

    private function draft(array $customer = ['email' => 'buyer@example.com', 'phone' => '+77011234567']): Certificate
    {
        return app(CertificateManager::class)->createDraft(['package_id' => $this->package->id, 'customer_id' => Customer::factory()->create($customer)->id]);
    }

    /** Ответы банка: токен, создание ссылки, проверка статуса (меняется в $state). */
    private function fakeBank(array &$state): void
    {
        Http::fake(function (Request $request) use (&$state) {
            $url = $request->url();
            if (str_ends_with($url, '/oauth2/token')) {
                return Http::response(['access_token' => 'tok-'.bin2hex(random_bytes(4)), 'expires_in' => 7200]);
            }
            if (str_ends_with($url, '/invoice')) {
                $state['invoice_request'] = $request->data();

                return Http::response(['id' => 'link-1', 'invoice_url' => 'https://pay.bank.example/i/abc', 'status' => 'ACTIVE']);
            }
            if (str_contains($url, '/check-status/payment/transaction/')) {
                $invoice = basename(parse_url($url, PHP_URL_PATH));

                return Http::response($state['by_invoice'][$invoice] ?? $state['check'] ?? ['resultCode' => '102']);
            }
            if (str_contains($url, '/deactivate/invoice-links/')) {
                $state['deactivated'] = true;

                return Http::response([]);
            }

            return Http::response([], 404);
        });
    }

    private function charged(Payment $payment, array $override = []): array
    {
        return ['resultCode' => '100', 'resultMessage' => 'SUCCESS', 'transaction' => $override + [
            'id' => 'tx-1', 'invoiceID' => $payment->epay_invoice_id, 'amount' => 50000, 'currency' => 'KZT', 'terminalID' => self::TERMINAL, 'statusName' => 'CHARGE']];
    }

    public function test_link_is_created_at_bank_and_emailed_without_naming_the_bank(): void
    {
        Mail::fake();
        $state = [];
        $this->fakeBank($state);
        $certificate = $this->draft();

        $payment = app(EpayPayments::class)->createLink($certificate, null, 'kk');

        $this->assertSame(PaymentMethod::HalykEpay, $payment->method);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(str_pad((string) $payment->id, 9, '0', STR_PAD_LEFT), $payment->epay_invoice_id);
        $this->assertSame('https://pay.bank.example/i/abc', $payment->pay_url);
        $this->assertSame('50000.00', $payment->amount);
        $request = $state['invoice_request'];
        $this->assertSame(self::TERMINAL, $request['shop_id']);
        $this->assertEquals(50000, $request['amount']);
        $this->assertSame('kaz', $request['language']);
        $this->assertSame('3d', $request['expire_period']);
        $this->assertSame('KZT', $request['currency']);
        $this->assertSame(route('epay.postlink'), $request['post_link']);
        $this->assertSame('buyer@example.com', $request['recipient_contact']);

        Mail::assertQueued(EpayPaymentLinkMail::class, function (EpayPaymentLinkMail $mail) {
            $html = strtolower($mail->render());

            return $mail->hasTo('buyer@example.com') && $mail->locale === 'kk' && str_contains($html, 'https://pay.bank.example/i/abc') && ! str_contains($html, 'halyk') && ! str_contains($html, 'epay');
        });
        $history = $certificate->history()->where('event', HistoryEvent::PaymentLinkSent)->firstOrFail();
        $this->assertStringNotContainsString('buyer@example.com', $history->description);
    }

    public function test_description_is_cut_by_bytes_and_every_operation_gets_its_own_token(): void
    {
        $text = str_repeat('Я', 100); // 200 байт
        $cut = EpayClient::trimBytes($text, EpayClient::DESCRIPTION_BYTES);
        $this->assertLessThanOrEqual(125, strlen($cut));
        $this->assertTrue(mb_check_encoding($cut, 'UTF-8'));

        $state = [];
        $this->fakeBank($state);
        app(EpayPayments::class)->createLink($this->draft(), null, 'ru');
        $tokenCalls = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/oauth2/token'));
        $this->assertCount(1, $tokenCalls);
        $this->assertStringContainsString('test-epay-oauth.', $tokenCalls->first()[0]->url());
        $this->assertSame('payment', $tokenCalls->first()[0]->data()['scope']);

        $payment = Payment::firstOrFail();
        $state['check'] = ['resultCode' => '107'];
        app(EpayPayments::class)->check($payment);
        app(EpayPayments::class)->check($payment);
        $this->assertCount(3, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/oauth2/token')));
        $withTerminal = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/oauth2/token') && ($r->data()['terminal'] ?? null) === self::TERMINAL);
        $this->assertCount(2, $withTerminal);
    }

    public function test_callback_pays_only_after_bank_confirms_and_is_idempotent_and_auto_reconciled(): void
    {
        Mail::fake();
        $state = [];
        $this->fakeBank($state);
        $certificate = $this->draft();
        $payment = app(EpayPayments::class)->createLink($certificate);

        // Банк «сообщает» об оплате, но API говорит, что денег нет: оплата не засчитывается.
        $state['check'] = ['resultCode' => '100', 'transaction' => ['statusName' => 'NEW', 'amount' => 50000, 'currency' => 'KZT', 'terminalID' => self::TERMINAL]];
        $this->postJson('/pay/halyk/postlink', ['invoiceId' => $payment->epay_invoice_id, 'code' => 'ok', 'amount' => 50000])->assertOk();
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        $state['check'] = $this->charged($payment);
        $this->postJson('/pay/halyk/postlink', ['invoiceId' => $payment->epay_invoice_id])->assertOk()->assertSee('OK');
        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('tx-1', $payment->epay_transaction_id);
        $this->assertTrue($payment->isReconciled());
        $this->assertNull($payment->reconciled_by);

        // Повторное уведомление ничего не меняет и не создаёт вторую запись об оплате.
        $this->postJson('/pay/halyk/postlink', ['invoiceId' => $payment->epay_invoice_id])->assertOk();
        $this->assertSame(1, $certificate->history()->where('event', HistoryEvent::PaymentPaid)->count());

        $this->assertTrue($certificate->refresh()->isReadyToIssue());
        $this->assertSame(CertificateStatus::Active, app(CertificateManager::class)->issue($certificate)->status);
    }

    public function test_mismatching_amount_currency_or_terminal_is_not_paid_and_is_flagged(): void
    {
        $state = [];
        $this->fakeBank($state);
        $payment = app(EpayPayments::class)->createLink($this->draft());

        foreach ([['amount' => 100], ['currency' => 'USD'], ['terminalID' => 'other-terminal']] as $wrong) {
            $state['check'] = $this->charged($payment, $wrong);
            $this->assertSame(EpayPayments::REVIEW, app(EpayPayments::class)->check($payment));
            $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        }
        $this->assertTrue($payment->certificate->history()->where('event', HistoryEvent::GatewayNote)->exists());
    }

    public function test_callback_ignores_garbage_and_unknown_invoices_and_needs_no_csrf(): void
    {
        $state = [];
        $this->fakeBank($state);
        $this->post('/pay/halyk/postlink', ['invoiceId' => "1' OR 1=1"])->assertOk();
        $this->postJson('/pay/halyk/postlink', ['invoiceId' => '999999999'])->assertOk();
        $this->post('/pay/halyk/postlink')->assertOk();
        $this->assertCount(0, Http::recorded());
    }

    public function test_payment_cannot_be_marked_by_hand_or_created_as_manual_invoice(): void
    {
        $state = [];
        $this->fakeBank($state);
        $certificate = $this->draft();
        $payment = app(EpayPayments::class)->createLink($certificate);

        $this->expectException(DomainRuleViolation::class);
        try {
            app(PaymentManager::class)->markPaid($payment);
        } finally {
            $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
            try {
                app(PaymentManager::class)->invoice($this->draft(), PaymentMethod::HalykEpay);
                $this->fail('Ручной счёт с оплатой картой должен быть запрещён.');
            } catch (DomainRuleViolation) {
                $this->assertArrayNotHasKey('halyk_epay', PaymentMethod::manualOptions());
            }
        }
    }

    public function test_cancel_deactivates_link_and_is_refused_when_money_already_arrived(): void
    {
        $state = [];
        $this->fakeBank($state);
        $payments = app(PaymentManager::class);
        $first = app(EpayPayments::class)->createLink($this->draft());
        $state['check'] = ['resultCode' => '107'];
        $payments->cancel($first, 'Передумали');
        $this->assertTrue($state['deactivated']);
        $this->assertSame(PaymentStatus::Cancelled, $first->refresh()->status);

        $second = app(EpayPayments::class)->createLink($this->draft());
        $state['check'] = $this->charged($second);
        try {
            $payments->cancel($second, 'Поздно');
            $this->fail('Отмена оплаченного платежа должна быть запрещена.');
        } catch (DomainRuleViolation) {
            $this->assertSame(PaymentStatus::Paid, $second->refresh()->status);
        }
    }

    public function test_payment_on_cancelled_link_is_not_booked_but_flagged(): void
    {
        $state = [];
        $this->fakeBank($state);
        $payment = app(EpayPayments::class)->createLink($this->draft());
        $state['check'] = ['resultCode' => '107'];
        app(PaymentManager::class)->cancel($payment, 'Отмена');

        $state['check'] = $this->charged($payment);
        $this->assertSame(EpayPayments::REVIEW, app(EpayPayments::class)->check($payment));
        $this->assertSame(PaymentStatus::Cancelled, $payment->refresh()->status);
    }

    public function test_schedule_pays_missed_notifications_and_cancels_expired_links(): void
    {
        $state = [];
        $this->fakeBank($state);
        $paidLater = app(EpayPayments::class)->createLink($this->draft());
        $expired = app(EpayPayments::class)->createLink($this->draft());
        $fresh = app(EpayPayments::class)->createLink($this->draft());

        // Прошло 11 минут: уведомление не пришло, но банк деньги списал (по остальным — «в процессе»).
        Carbon::setTestNow(now()->addMinutes(11));
        $state['check'] = ['resultCode' => '107'];
        $state['by_invoice'][$paidLater->epay_invoice_id] = $this->charged($paidLater);
        $this->artisan('gifter:epay-reconcile')->expectsOutputToContain('Оплачено: 1')->assertSuccessful();
        $this->assertSame(PaymentStatus::Paid, $paidLater->refresh()->status);
        $this->assertSame(PaymentStatus::Pending, $fresh->refresh()->status);

        // Ссылка истекла (3 дня) — платёж отменяется.
        Carbon::setTestNow(now()->addDays(4));
        $this->artisan('gifter:epay-reconcile')->expectsOutputToContain('ссылок истекло: 2')->assertSuccessful();
        $this->assertSame(PaymentStatus::Cancelled, $expired->refresh()->status);
        $this->assertSame(PaymentStatus::Paid, $paidLater->refresh()->status);
    }

    public function test_bank_failure_cancels_the_payment_and_shows_a_message(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response([], 500)]);
        $certificate = $this->draft();

        try {
            app(EpayPayments::class)->createLink($certificate);
            $this->fail('Сбой банка должен остановить создание ссылки.');
        } catch (DomainRuleViolation $e) {
            $this->assertStringContainsString('Банк', $e->getMessage());
        }
        $this->assertSame(PaymentStatus::Cancelled, Payment::firstOrFail()->status);
        Mail::assertNothingQueued();
    }

    public function test_link_needs_email_phone_and_settings_and_is_hidden_without_keys(): void
    {
        $state = [];
        $this->fakeBank($state);
        foreach ([['email' => null, 'phone' => '+77011234567'], ['email' => 'a@example.com', 'phone' => null]] as $customer) {
            try {
                app(EpayPayments::class)->createLink($this->draft($customer));
                $this->fail('Без email или телефона ссылку создавать нельзя.');
            } catch (DomainRuleViolation) {
                $this->assertSame(0, Payment::count());
            }
        }

        config(['gifter.epay.client_secret' => null]);
        $certificate = $this->draft();
        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])->assertActionHidden('epayLink');
        $this->expectException(DomainRuleViolation::class);
        app(EpayPayments::class)->createLink($certificate);
    }

    public function test_certificate_card_actions_for_epay(): void
    {
        Mail::fake();
        $state = [];
        $this->fakeBank($state);
        $certificate = $this->draft();

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->assertActionVisible('epayLink')->assertActionHidden('epayResend')
            ->callAction('epayLink', ['email' => '', 'lang' => 'ru'])->assertHasNoActionErrors();
        $payment = Payment::firstOrFail();
        Mail::assertQueuedCount(1);

        $component = Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->assertActionHidden('epayLink')->assertActionHidden('markPaid')->assertActionVisible('epayResend')->assertActionVisible('epayCheck');
        $component->callAction('epayResend', ['email' => 'other@example.com', 'lang' => 'en']);
        Mail::assertQueuedCount(2);

        $state['check'] = ['resultCode' => '107'];
        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])->callAction('epayCheck');
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $state['check'] = $this->charged($payment);
        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])->callAction('epayCheck');
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
    }

    public function test_security_check_warns_about_partial_keys_and_test_terminal_on_production(): void
    {
        config(['gifter.epay.client_secret' => null]);
        $failed = collect(app(SecurityAudit::class)->run(checkHttp: false))->where('status', '!=', SecurityAudit::OK)->pluck('check');
        $this->assertTrue($failed->contains('Оплата картой: ключи заданы полностью'));

        config(['gifter.epay.client_secret' => 'fictional-secret']);
        $this->app['env'] = 'production';
        $failed = collect(app(SecurityAudit::class)->run(checkHttp: false))->where('status', '!=', SecurityAudit::OK)->pluck('check');
        $this->assertTrue($failed->contains('Оплата картой: боевой терминал'));
    }
}
