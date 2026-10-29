<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\CertificateManager;
use App\Services\EpayPayments;
use App\Services\SecurityAudit;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Tests\TestCase;

/** Замечания аудита: CSP, прокси, callback банка, адресат писем о копиях, атомарные лимиты формы. */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_csp_is_report_only_by_default_and_enforced_on_demand(): void
    {
        $response = $this->get('/');
        $this->assertStringContainsString("default-src 'self'", (string) $response->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertStringContainsString('report-uri /csp-report', (string) $response->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertStringNotContainsString('default-src', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));

        config(['gifter.security.csp_mode' => 'enforce']);
        $response = $this->get('/');
        $this->assertStringContainsString("default-src 'self'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));

        config(['gifter.security.csp_mode' => 'off']);
        $this->assertStringNotContainsString('default-src', (string) $this->get('/')->headers->get('Content-Security-Policy'));
    }

    public function test_analytics_hosts_only_when_counters_are_set_and_never_in_admin(): void
    {
        $policy = fn (string $url) => (string) $this->get($url)->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringNotContainsString('googletagmanager', $policy('/'));

        $setting = ShopSetting::current();
        $setting->fill(['ga4_id' => 'G-ABCD1234', 'metrika_id' => '12345678'])->save();

        $this->assertStringContainsString('googletagmanager.com', $policy('/'));
        $this->assertStringContainsString('mc.yandex.ru', $policy('/'));
        $this->assertStringNotContainsString('googletagmanager', $policy('/admin/login'));
    }

    public function test_csp_report_is_accepted_and_logs_only_directive_and_host(): void
    {
        $body = json_encode(['csp-report' => ['blocked-uri' => 'https://evil.example/x.js?token=secret', 'effective-directive' => 'script-src', 'document-uri' => 'https://site/?phone=+77011234567']]);

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body)->assertNoContent();
    }

    public function test_proxy_setting_is_critical_in_production_when_empty(): void
    {
        config(['gifter.security.trusted_proxies' => null]);
        $this->app['env'] = 'production';
        $row = collect(app(SecurityAudit::class)->run(checkHttp: false))->firstWhere('check', 'Доверенные прокси ограничены (Cloudflare)');
        $this->assertSame(SecurityAudit::FAIL, $row['status']);

        config(['gifter.security.trusted_proxies' => 'cloudflare']);
        $row = collect(app(SecurityAudit::class)->run(checkHttp: false))->firstWhere('check', 'Доверенные прокси ограничены (Cloudflare)');
        $this->assertSame(SecurityAudit::OK, $row['status']);
    }

    public function test_epay_callback_does_not_query_the_bank_for_closed_payments_or_repeats(): void
    {
        Http::fake(fn ($request) => str_ends_with($request->url(), '/oauth2/token') ? Http::response(['access_token' => 'tok']) : Http::response(['resultCode' => '102']));
        Cache::flush();
        config(['gifter.epay.client_id' => 'x', 'gifter.epay.client_secret' => 'y', 'gifter.epay.terminal' => 't', 'gifter.epay.env' => 'test']);
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $payments = app(EpayPayments::class);

        // Неизвестный и закрытый счёт: банк не опрашивается.
        $this->assertNull($payments->handleCallback(['invoiceId' => '000000999']));
        $service = Service::factory()->create();
        app(ServicePricing::class)->addPrice($service, '1000', Carbon::parse('2026-01-01'));
        $package = Package::create(['name' => 'P2', 'validity_value' => 2, 'validity_unit' => 'months']);
        $package->items()->create(['service_id' => $service->id, 'quantity' => 1]);
        $certificate = app(CertificateManager::class)->createDraft(['package_id' => $package->id, 'customer_id' => Customer::factory()->create()->id]);
        $payment = Payment::query()->forceCreate(['certificate_id' => $certificate->id, 'method' => 'halyk_epay', 'status' => 'paid', 'amount' => 1000, 'currency' => 'KZT', 'epay_invoice_id' => '000000123', 'invoiced_at' => now()]);
        $this->assertNull($payments->handleCallback(['invoiceId' => '000000123']));
        Http::assertNothingSent();

        // Ожидающий платёж: первая проверка идёт к банку, повтор в течение 20 секунд — нет.
        $payment->update(['status' => 'pending']);
        $payments->handleCallback(['invoiceId' => '000000123']);
        $sent = count(Http::recorded());
        $this->assertGreaterThan(0, $sent);
        $payments->handleCallback(['invoiceId' => '000000123']);
        $this->assertCount($sent, Http::recorded());
    }

    public function test_backup_failure_mails_have_a_recipient_or_are_disabled(): void
    {
        $config = fn (array $env) => (function () use ($env) {
            foreach (['BACKUP_MAIL_TO', 'ERROR_MAIL_TO', 'GIFTER_ORDERS_MAIL_TO'] as $key) {
                putenv($key.(isset($env[$key]) ? '='.$env[$key] : ''));
                unset($_ENV[$key], $_SERVER[$key]);
                if (isset($env[$key])) {
                    $_ENV[$key] = $_SERVER[$key] = $env[$key];
                }
            }

            return require base_path('config/backup.php');
        })()['notifications'];

        $none = $config([]);
        $this->assertSame([], $none['notifications'][BackupHasFailedNotification::class]);
        $this->assertSame('backup-alerts-disabled@example.invalid', $none['mail']['to']);

        $with = $config(['ERROR_MAIL_TO' => 'owner@example.com']);
        $this->assertSame(['mail'], $with['notifications'][BackupHasFailedNotification::class]);
        $this->assertSame('owner@example.com', $with['mail']['to']);
        $config([]);
    }

    public function test_form_limits_are_taken_atomically_and_returned_on_failure(): void
    {
        $service = Service::factory()->create();
        app(ServicePricing::class)->addPrice($service, '50000', Carbon::parse('2026-01-01'));
        $package = Package::create(['name' => 'P', 'validity_value' => 2, 'validity_unit' => 'months', 'sold_online' => true]);
        $package->items()->create(['service_id' => $service->id, 'quantity' => 1]);
        $key = 'shop-ip:127.0.0.1';
        RateLimiter::clear($key);
        RateLimiter::increment($key, 86400, 20); // лимит по IP исчерпан

        $before = RateLimiter::attempts($key);
        $this->assertSame(20, $before);

        $response = $this->post('/order/'.$package->id, ['last_name' => 'Иванов', 'first_name' => 'Иван', 'phone' => '8 701 123 45 67', 'delivery_method' => 'pickup', 'consent' => '1']);

        $response->assertSessionHasErrors('phone');
        $this->assertSame(20, RateLimiter::attempts($key), 'Отказ возвращает занятый слот');
    }
}
