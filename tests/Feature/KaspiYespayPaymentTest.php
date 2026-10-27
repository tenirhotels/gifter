<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Mail\PaymentAlertMail;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Models\YespayWebhookEvent;
use App\Services\CertificateManager;
use App\Services\KaspiYespay\KaspiYespayClient;
use App\Services\KaspiYespayPayments;
use App\Services\PaymentManager;
use App\Services\SecurityAudit;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/** Счета Kaspi через посредника: все обращения подменены (Http::fake), ключи вымышленные. */
class KaspiYespayPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_fictional';

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-15 12:00:00');
        Cache::flush();
        config(['gifter.kaspi_yespay.enabled' => true, 'gifter.kaspi_yespay.api_key' => 'sk_test_fictional', 'gifter.kaspi_yespay.webhook_secret' => self::SECRET,
            'gifter.kaspi_yespay.base_url' => 'https://kaspi.example', 'gifter.kaspi_yespay.expire_minutes' => 60]);
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $service = Service::factory()->create();
        app(ServicePricing::class)->addPrice($service, '50000', Carbon::parse('2026-01-01'));
        $this->package = Package::create(['name' => 'Вечер', 'validity_value' => 3, 'validity_unit' => 'months']);
        $this->package->items()->create(['service_id' => $service->id, 'quantity' => 1]);
    }

    private function draft(string $phone = '+77011234567'): Certificate
    {
        return app(CertificateManager::class)->createDraft(['package_id' => $this->package->id, 'customer_id' => Customer::factory()->create(['phone' => $phone])->id]);
    }

    /** @param  array<string, mixed>  $state */
    private function fake(array &$state): void
    {
        Http::fake(function (Request $request) use (&$state) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/payments')) {
                $state['create'] = $request->data();
                $state['key'] = $request->header('Idempotency-Key')[0] ?? null;
                $state['auth'] = $request->header('Authorization')[0] ?? null;

                $state['n'] = ($state['n'] ?? 0) + 1;

                return Http::response(['id' => 'pay_'.$state['n'], 'status' => 'pending'], 201);
            }
            if (str_ends_with($request->url(), '/cancel')) {
                $state['cancelled'] = true;

                return Http::response(['id' => 'pay_1', 'status' => 'cancelled']);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/v1/payments/pay_')) {
                return Http::response($state['status'] ?? ['id' => 'pay_1', 'status' => 'pending', 'amount' => 50000, 'currency' => 'KZT']);
            }

            return Http::response([], 404);
        });
    }

    private function signed(string $body, ?int $time = null, ?string $secret = null): array
    {
        $t = $time ?? time();

        return ['X-Yespay-Signature' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$body, $secret ?? self::SECRET), 'X-Yespay-Event-Id' => 'evt_'.bin2hex(random_bytes(4)), 'Content-Type' => 'application/json'];
    }

    public function test_invoice_is_created_with_whole_tenge_phone_and_idempotency_key(): void
    {
        $state = [];
        $this->fake($state);

        $payment = app(KaspiYespayPayments::class)->createInvoice($this->draft());

        $this->assertSame(PaymentMethod::KaspiYespay, $payment->method);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('pay_1', $payment->kaspi_yespay_payment_id);
        $this->assertSame(50000, $state['create']['amount']);
        $this->assertSame('KZT', $state['create']['currency']);
        $this->assertSame('+77011234567', $state['create']['customer']['phone']);
        $this->assertSame($payment->invoice_number, $state['create']['order_id']);
        $this->assertSame($payment->kaspi_yespay_idempotency_key, $state['key']);
        $this->assertSame('Bearer sk_test_fictional', $state['auth']);
    }

    public function test_invoice_needs_kazakhstan_mobile_and_leaves_no_payment_on_failure(): void
    {
        $certificate = $this->draft('+998901234567');

        try {
            app(KaspiYespayPayments::class)->createInvoice($certificate);
            $this->fail('Ожидалась ошибка');
        } catch (DomainRuleViolation) {
        }
        $this->assertSame(0, Payment::query()->count());

        Http::fake(['*' => Http::response(['error' => ['code' => 'payment_account_reauth_required']], 409)]);
        try {
            app(KaspiYespayPayments::class)->createInvoice($this->draft());
            $this->fail('Ожидалась ошибка');
        } catch (DomainRuleViolation $e) {
            $this->assertStringContainsString('отключился', $e->getMessage());
        }
        $this->assertSame(PaymentStatus::Cancelled, Payment::query()->firstOrFail()->status, 'Не созданный у посредника счёт отменяется');
    }

    public function test_unknown_status_changes_nothing_and_paid_settles_once(): void
    {
        $state = [];
        $this->fake($state);
        $certificate = $this->draft();
        $payment = app(KaspiYespayPayments::class)->createInvoice($certificate);

        $state['status'] = ['id' => 'pay_1', 'status' => 'unknown'];
        $this->assertSame(KaspiYespayPayments::PENDING, app(KaspiYespayPayments::class)->check($payment));
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        $state['status'] = ['id' => 'pay_1', 'status' => 'paid', 'amount' => 50000, 'currency' => 'KZT'];
        $this->assertSame(KaspiYespayPayments::PAID, app(KaspiYespayPayments::class)->check($payment));
        $this->assertSame(KaspiYespayPayments::PAID, app(KaspiYespayPayments::class)->check($payment));
        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->reconciled_at, 'Подтверждено API — сверка Финансами не нужна');
    }

    public function test_paid_with_wrong_amount_needs_review(): void
    {
        $state = [];
        $this->fake($state);
        $payment = app(KaspiYespayPayments::class)->createInvoice($this->draft());

        $state['status'] = ['id' => 'pay_1', 'status' => 'paid', 'amount' => 49999, 'currency' => 'KZT'];

        $this->assertSame(KaspiYespayPayments::REVIEW, app(KaspiYespayPayments::class)->check($payment));
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    /** @param  array<string, mixed>  $data */
    private function event(string $type, array $data, ?string $id = null): array
    {
        $body = json_encode(['id' => $id ?? 'evt_'.bin2hex(random_bytes(3)), 'type' => $type, 'createdAt' => now()->toIso8601String(), 'livemode' => false, 'data' => $data]);

        return [$body, $this->signed($body) + ['X-Yespay-Event-Id' => $id ?? 'evt_'.bin2hex(random_bytes(3))]];
    }

    private function deliver(string $body, array $headers): TestResponse
    {
        return $this->call('POST', '/pay/kaspi-yespay/webhook', [], [], [], $this->server($headers), $body);
    }

    public function test_webhook_rejects_bad_signature_and_stale_timestamp_with_400_and_runs_nothing(): void
    {
        $state = [];
        $this->fake($state);
        $payment = app(KaspiYespayPayments::class)->createInvoice($this->draft());
        $state['status'] = ['id' => 'pay_1', 'status' => 'paid', 'amount' => 50000, 'currency' => 'KZT'];
        [$body] = $this->event('payment.paid', ['id' => 'pay_1', 'amount' => 50000]);

        $this->deliver($body, $this->signed($body, secret: 'wrong'))->assertStatus(400);
        $this->deliver($body, $this->signed($body, time() - 1000))->assertStatus(400);
        $this->deliver($body.' ', $this->signed($body))->assertStatus(400); // тело изменено после подписи
        $this->deliver($body, ['Content-Type' => 'application/json'])->assertStatus(400);

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(0, YespayWebhookEvent::query()->count());
    }

    public function test_valid_paid_event_settles_and_repeated_delivery_is_not_processed_twice(): void
    {
        $state = [];
        $this->fake($state);
        $payment = app(KaspiYespayPayments::class)->createInvoice($this->draft());
        $state['status'] = ['id' => 'pay_1', 'status' => 'paid', 'amount' => 50000, 'currency' => 'KZT'];
        $body = json_encode(['id' => 'evt_1', 'type' => 'payment.paid', 'createdAt' => now()->toIso8601String(), 'livemode' => false, 'data' => ['id' => 'pay_1', 'order_id' => $payment->invoice_number, 'amount' => 50000]]);
        $headers = $this->signed($body) + [];
        $headers['X-Yespay-Event-Id'] = 'evt_fixed';

        $this->deliver($body, $headers)->assertOk();
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        $reads = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'GET')->count();

        $repeat = $this->signed($body);
        $repeat['X-Yespay-Event-Id'] = 'evt_fixed';
        $this->deliver($body, $repeat)->assertOk();
        $this->assertSame($reads, collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'GET')->count(), 'Повтор того же события ничего не выполняет');
        $this->assertSame(1, YespayWebhookEvent::query()->where('event_id', 'evt_fixed')->count());
    }

    public function test_unknown_event_type_is_ignored_with_200(): void
    {
        [$body, $headers] = $this->event('something.new', ['id' => 'pay_x']);

        $this->deliver($body, $headers)->assertOk();
        $this->assertSame(1, YespayWebhookEvent::query()->count());
    }

    public function test_paid_event_with_wrong_amount_does_not_settle_and_alerts(): void
    {
        Mail::fake();
        config(['gifter.kaspi_yespay.alert_mail_to' => 'ops@example.com']);
        $state = [];
        $this->fake($state);
        $payment = app(KaspiYespayPayments::class)->createInvoice($this->draft());
        $state['status'] = ['id' => 'pay_1', 'status' => 'paid', 'amount' => 50000, 'currency' => 'KZT'];
        [$body, $headers] = $this->event('payment.paid', ['id' => 'pay_1', 'amount' => 49000]);

        $this->deliver($body, $headers)->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertNotNull($payment->needs_review_at);
        Mail::assertQueued(PaymentAlertMail::class, 1);
    }

    public function test_unknown_status_event_marks_review_keeps_order_open_and_alerts_once(): void
    {
        Mail::fake();
        config(['gifter.kaspi_yespay.alert_mail_to' => 'ops@example.com']);
        $state = [];
        $this->fake($state);
        $certificate = $this->draft();
        $payment = app(KaspiYespayPayments::class)->createInvoice($certificate);

        foreach ([1, 2] as $_) {
            [$body, $headers] = $this->event('payment.status_unknown', ['id' => 'pay_1']);
            $this->deliver($body, $headers)->assertOk();
        }

        $payment->refresh();
        $this->assertSame(PaymentStatus::Pending, $payment->status, 'Не отказ и не оплата');
        $this->assertNotNull($payment->needs_review_at);
        $this->assertArrayNotHasKey('cancelled', $state, 'Счёт у посредника не отменялся');
        $this->assertFalse($certificate->refresh()->isReadyToIssue(), 'Сертификат не выпускается');
        Mail::assertQueued(PaymentAlertMail::class, 1);

        $this->expectException(DomainRuleViolation::class); // отмена вручную тоже запрещена, пока результат неизвестен
        $state['status'] = ['id' => 'pay_1', 'status' => 'unknown'];
        app(PaymentManager::class)->cancel($payment, 'Проверка');
    }

    public function test_failed_expired_cancelled_events_close_the_payment(): void
    {
        $state = [];
        $this->fake($state);
        foreach (['failed', 'expired', 'cancelled'] as $status) {
            $payment = app(KaspiYespayPayments::class)->createInvoice($this->draft());
            $state['status'] = ['id' => 'pay_'.$state['n'], 'status' => $status];
            [$body, $headers] = $this->event('payment.'.$status, ['id' => 'pay_'.$state['n']]);

            $this->deliver($body, $headers)->assertOk();

            $this->assertSame(PaymentStatus::Cancelled, $payment->refresh()->status, $status);
        }
        $this->assertArrayNotHasKey('cancelled', $state, 'У посредника уже закрыто — повторно не отменяем');
    }

    public function test_reauth_event_reaches_a_person_by_mail(): void
    {
        Mail::fake();
        config(['gifter.kaspi_yespay.alert_mail_to' => 'ops@example.com']);
        [$body, $headers] = $this->event('payment_account.reauth_required', []);

        $this->deliver($body, $headers)->assertOk();

        Mail::assertQueued(PaymentAlertMail::class, fn ($m) => $m->hasTo('ops@example.com'));
        Mail::assertQueued(PaymentAlertMail::class, fn ($m) => str_contains(mb_strtolower($m->text), 'kaspi'));
    }

    public function test_refund_event_is_noted_and_reported(): void
    {
        Mail::fake();
        config(['gifter.kaspi_yespay.alert_mail_to' => 'ops@example.com']);
        $state = [];
        $this->fake($state);
        app(KaspiYespayPayments::class)->createInvoice($this->draft());
        [$body, $headers] = $this->event('refund.succeeded', ['payment_id' => 'pay_1']);

        $this->deliver($body, $headers)->assertOk();

        Mail::assertQueued(PaymentAlertMail::class, 1);
    }

    public function test_idempotency_key_is_derived_from_the_order_and_retries_reuse_it(): void
    {
        Sleep::fake();
        $payment = app(KaspiYespayPayments::class);
        $certificate = $this->draft();
        Http::fake(['*' => Http::sequence()->push([], 503)->push([], 429)->push(['id' => 'pay_1', 'status' => 'pending'], 201)]);

        $created = $payment->createInvoice($certificate);

        $this->assertSame($created->invoice_number, $created->kaspi_yespay_idempotency_key, 'Ключ — от номера счёта, а не случайный');
        $posts = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => $r->method() === 'POST');
        $this->assertCount(3, $posts);
        $this->assertSame([$created->invoice_number], $posts->map(fn (Request $r) => $r->header('Idempotency-Key')[0])->unique()->values()->all());
    }

    public function test_client_error_is_not_retried(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response(['error' => ['code' => 'invalid_request']], 400)]);
        try {
            app(KaspiYespayPayments::class)->createInvoice($this->draft());
            $this->fail('Ожидалась ошибка');
        } catch (DomainRuleViolation) {
        }

        $this->assertCount(1, Http::recorded());
    }

    public function test_retries_stop_after_five_attempts_with_growing_pause(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response([], 503)]);
        try {
            app(KaspiYespayPayments::class)->createInvoice($this->draft());
            $this->fail('Ожидалась ошибка');
        } catch (DomainRuleViolation) {
        }

        $this->assertCount(5, Http::recorded(), 'Не больше 5 попыток');
    }

    public function test_client_finds_payment_by_order_id_and_refund_sends_idempotency_key(): void
    {
        Http::fake([
            '*/v1/payments?*' => Http::response(['data' => [['id' => 'pay_9', 'status' => 'paid', 'amount' => 100, 'currency' => 'KZT', 'order_id' => 'K1']]]),
            '*/v1/refunds' => Http::response(['id' => 'ref_1']),
        ]);
        $client = app(KaspiYespayClient::class);

        $found = $client->findByOrderId('K1');
        $this->assertSame('pay_9', $found['id']);

        $payment = new Payment(['invoice_number' => 'K000000001', 'kaspi_yespay_payment_id' => 'pay_9']);
        $client->refund($payment);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/refunds') && $r->header('Idempotency-Key')[0] === 'refund-K000000001');
    }

    /** @param  array<string, string>  $headers */
    private function server(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server + ['CONTENT_TYPE' => 'application/json'];
    }

    public function test_cancel_disables_invoice_but_not_after_payment(): void
    {
        $state = [];
        $this->fake($state);
        $payment = app(KaspiYespayPayments::class)->createInvoice($this->draft());

        app(PaymentManager::class)->cancel($payment, 'Клиент передумал');
        $this->assertTrue($state['cancelled']);
        $this->assertSame(PaymentStatus::Cancelled, $payment->refresh()->status);

        $second = app(KaspiYespayPayments::class)->createInvoice($this->draft());
        $state['status'] = ['id' => 'pay_1', 'status' => 'paid', 'amount' => 50000, 'currency' => 'KZT'];
        $this->expectException(DomainRuleViolation::class);
        app(PaymentManager::class)->cancel($second, 'Поздно');
    }

    public function test_reconcile_pays_missed_and_cancels_expired(): void
    {
        $state = [];
        $this->fake($state);
        $paid = app(KaspiYespayPayments::class)->createInvoice($this->draft());
        $state['status'] = ['id' => 'pay_1', 'status' => 'paid', 'amount' => 50000, 'currency' => 'KZT'];
        Carbon::setTestNow(now()->addMinutes(15));

        $this->assertSame(['paid' => 1, 'expired' => 0], app(KaspiYespayPayments::class)->reconcilePending());
        $this->assertSame(PaymentStatus::Paid, $paid->refresh()->status);

        $expired = app(KaspiYespayPayments::class)->createInvoice($this->draft());
        $state['status'] = ['id' => 'pay_1', 'status' => 'pending', 'amount' => 50000, 'currency' => 'KZT'];
        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(['paid' => 0, 'expired' => 1], app(KaspiYespayPayments::class)->reconcilePending());
        $this->assertSame(PaymentStatus::Cancelled, $expired->refresh()->status);
    }

    public function test_action_is_hidden_when_disabled_and_checked_by_security_audit(): void
    {
        $certificate = $this->draft();
        Livewire::test(ViewCertificate::class, ['record' => $certificate->getRouteKey()])->assertActionVisible('kaspiInvoice');

        config(['gifter.kaspi_yespay.enabled' => false]);
        Livewire::test(ViewCertificate::class, ['record' => $certificate->getRouteKey()])->assertActionHidden('kaspiInvoice');

        config(['gifter.kaspi_yespay.enabled' => true, 'gifter.kaspi_yespay.api_key' => null]);
        $failed = collect(app(SecurityAudit::class)->run(checkHttp: false))->where('status', '!=', SecurityAudit::OK)->pluck('check');
        $this->assertTrue($failed->contains('Счета Kaspi: ключи заданы'));

        config(['gifter.kaspi_yespay.api_key' => 'sk_test_fictional']);
        $this->app['env'] = 'production';
        $failed = collect(app(SecurityAudit::class)->run(checkHttp: false))->where('status', '!=', SecurityAudit::OK)->pluck('check');
        $this->assertTrue($failed->contains('Счета Kaspi: боевой ключ'));
    }
}
