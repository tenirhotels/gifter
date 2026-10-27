<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\CertificateManager;
use App\Services\KaspiPayments;
use App\Services\PaymentManager;
use App\Services\SecurityAudit;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Счета Kaspi через посредника: все обращения подменены (Http::fake), ключи вымышленные. */
class KaspiPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_fictional';

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-15 12:00:00');
        Cache::flush();
        config(['gifter.kaspi.enabled' => true, 'gifter.kaspi.api_key' => 'sk_test_fictional', 'gifter.kaspi.webhook_secret' => self::SECRET,
            'gifter.kaspi.base_url' => 'https://kaspi.example', 'gifter.kaspi.expire_minutes' => 60]);
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

        $payment = app(KaspiPayments::class)->createInvoice($this->draft());

        $this->assertSame(PaymentMethod::KaspiPay, $payment->method);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('pay_1', $payment->kaspi_payment_id);
        $this->assertSame(50000, $state['create']['amount']);
        $this->assertSame('KZT', $state['create']['currency']);
        $this->assertSame('+77011234567', $state['create']['customer']['phone']);
        $this->assertSame($payment->invoice_number, $state['create']['order_id']);
        $this->assertSame($payment->kaspi_idempotency_key, $state['key']);
        $this->assertSame('Bearer sk_test_fictional', $state['auth']);
    }

    public function test_invoice_needs_kazakhstan_mobile_and_leaves_no_payment_on_failure(): void
    {
        $certificate = $this->draft('+998901234567');

        try {
            app(KaspiPayments::class)->createInvoice($certificate);
            $this->fail('Ожидалась ошибка');
        } catch (DomainRuleViolation) {
        }
        $this->assertSame(0, Payment::query()->count());

        Http::fake(['*' => Http::response(['error' => ['code' => 'payment_account_reauth_required']], 409)]);
        try {
            app(KaspiPayments::class)->createInvoice($this->draft());
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
        $payment = app(KaspiPayments::class)->createInvoice($certificate);

        $state['status'] = ['id' => 'pay_1', 'status' => 'unknown'];
        $this->assertSame(KaspiPayments::PENDING, app(KaspiPayments::class)->check($payment));
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        $state['status'] = ['id' => 'pay_1', 'status' => 'paid', 'amount' => 50000, 'currency' => 'KZT'];
        $this->assertSame(KaspiPayments::PAID, app(KaspiPayments::class)->check($payment));
        $this->assertSame(KaspiPayments::PAID, app(KaspiPayments::class)->check($payment));
        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->reconciled_at, 'Подтверждено API — сверка Финансами не нужна');
    }

    public function test_paid_with_wrong_amount_needs_review(): void
    {
        $state = [];
        $this->fake($state);
        $payment = app(KaspiPayments::class)->createInvoice($this->draft());

        $state['status'] = ['id' => 'pay_1', 'status' => 'paid', 'amount' => 49999, 'currency' => 'KZT'];

        $this->assertSame(KaspiPayments::REVIEW, app(KaspiPayments::class)->check($payment));
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function test_webhook_signature_age_and_duplicates(): void
    {
        $state = [];
        $this->fake($state);
        $payment = app(KaspiPayments::class)->createInvoice($this->draft());
        $state['status'] = ['id' => 'pay_1', 'status' => 'paid', 'amount' => 50000, 'currency' => 'KZT'];
        $body = json_encode(['id' => 'evt_1', 'type' => 'payment.paid', 'createdAt' => now()->toIso8601String(), 'livemode' => false, 'data' => ['id' => 'pay_1', 'order_id' => $payment->invoice_number]]);

        $this->call('POST', '/pay/kaspi/webhook', [], [], [], $this->server($this->signed($body, secret: 'wrong')), $body)->assertStatus(401);
        $this->call('POST', '/pay/kaspi/webhook', [], [], [], $this->server($this->signed($body, time() - 1000)), $body)->assertStatus(401);
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        $headers = $this->signed($body);
        $this->call('POST', '/pay/kaspi/webhook', [], [], [], $this->server($headers), $body)->assertOk();
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);

        $this->assertNull(app(KaspiPayments::class)->handleWebhook($body, ['signature' => $headers['X-Yespay-Signature'], 'event_id' => $headers['X-Yespay-Event-Id']]), 'Повтор того же события игнорируется');
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
        $payment = app(KaspiPayments::class)->createInvoice($this->draft());

        app(PaymentManager::class)->cancel($payment, 'Клиент передумал');
        $this->assertTrue($state['cancelled']);
        $this->assertSame(PaymentStatus::Cancelled, $payment->refresh()->status);

        $second = app(KaspiPayments::class)->createInvoice($this->draft());
        $state['status'] = ['id' => 'pay_1', 'status' => 'paid', 'amount' => 50000, 'currency' => 'KZT'];
        $this->expectException(DomainRuleViolation::class);
        app(PaymentManager::class)->cancel($second, 'Поздно');
    }

    public function test_reconcile_pays_missed_and_cancels_expired(): void
    {
        $state = [];
        $this->fake($state);
        $paid = app(KaspiPayments::class)->createInvoice($this->draft());
        $state['status'] = ['id' => 'pay_1', 'status' => 'paid', 'amount' => 50000, 'currency' => 'KZT'];
        Carbon::setTestNow(now()->addMinutes(15));

        $this->assertSame(['paid' => 1, 'expired' => 0], app(KaspiPayments::class)->reconcilePending());
        $this->assertSame(PaymentStatus::Paid, $paid->refresh()->status);

        $expired = app(KaspiPayments::class)->createInvoice($this->draft());
        $state['status'] = ['id' => 'pay_1', 'status' => 'pending', 'amount' => 50000, 'currency' => 'KZT'];
        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(['paid' => 0, 'expired' => 1], app(KaspiPayments::class)->reconcilePending());
        $this->assertSame(PaymentStatus::Cancelled, $expired->refresh()->status);
    }

    public function test_action_is_hidden_when_disabled_and_checked_by_security_audit(): void
    {
        $certificate = $this->draft();
        Livewire::test(ViewCertificate::class, ['record' => $certificate->getRouteKey()])->assertActionVisible('kaspiInvoice');

        config(['gifter.kaspi.enabled' => false]);
        Livewire::test(ViewCertificate::class, ['record' => $certificate->getRouteKey()])->assertActionHidden('kaspiInvoice');

        config(['gifter.kaspi.enabled' => true, 'gifter.kaspi.api_key' => null]);
        $failed = collect(app(SecurityAudit::class)->run(checkHttp: false))->where('status', '!=', SecurityAudit::OK)->pluck('check');
        $this->assertTrue($failed->contains('Счета Kaspi: ключи заданы'));

        config(['gifter.kaspi.api_key' => 'sk_test_fictional']);
        $this->app['env'] = 'production';
        $failed = collect(app(SecurityAudit::class)->run(checkHttp: false))->where('status', '!=', SecurityAudit::OK)->pluck('check');
        $this->assertTrue($failed->contains('Счета Kaspi: боевой ключ'));
    }
}
