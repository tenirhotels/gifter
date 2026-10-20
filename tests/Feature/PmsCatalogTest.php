<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Pages\PmsServices;
use App\Models\PmsService;
use App\Models\PmsSetting;
use App\Models\Service;
use App\Models\User;
use App\Services\Pms\PmsCatalogSync;
use App\Services\Pms\PmsClient;
use App\Services\Pms\PmsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Список услуг из Exely (Content API): по ID, без тарифов и цен. */
class PmsCatalogTest extends TestCase
{
    use RefreshDatabase;

    private mixed $content = null;

    protected function setUp(): void
    {
        parent::setUp();
        PmsSetting::create(['enabled' => true, 'provider' => 'exely', 'property_code' => '9305', 'client_id' => 'fictional-client', 'client_secret' => 'fictional-secret']);
        Cache::flush();
        $this->content = Http::response($this->catalog());
        Http::fake(fn ($request) => str_contains($request->url(), '/auth/token')
            ? Http::response(['access_token' => 'fictional-token', 'expires_in' => 900])
            : (is_callable($this->content) ? ($this->content)() : $this->content));
    }

    /** @param  list<array<string, string>>|null  $services */
    private function catalog(?array $services = null): array
    {
        return ['id' => '9305', 'name' => 'Тестовый отель', 'currency' => 'KZT',
            'ratePlans' => [['id' => '20', 'name' => 'Стандартный тариф', 'availableServices' => [['id' => '30', 'included' => true]]]],
            'services' => $services ?? [
                ['id' => '30', 'name' => 'Завтрак', 'kind' => 'Meal', 'status' => 'Active'],
                ['id' => '31', 'name' => 'Канатная дорога', 'kind' => 'Common', 'status' => 'Active'],
            ]];
    }

    public function test_sync_loads_services_only_by_id_and_ignores_rate_plans(): void
    {
        $stats = app(PmsCatalogSync::class)->run();

        $this->assertSame(['new' => 2, 'updated' => 0, 'missing' => 0, 'total' => 2], $stats);
        $this->assertSame(['30', '31'], PmsService::orderBy('external_id')->pluck('external_id')->all());
        $this->assertSame(0, Service::count(), 'Услуги Gifter создаёт только тумблер');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/content/v1/properties/9305') && $r->hasHeader('Authorization', 'Bearer fictional-token'));
    }

    public function test_renamed_or_translated_name_updates_the_same_id_without_duplicates(): void
    {
        app(PmsCatalogSync::class)->run();
        $pms = PmsService::where('external_id', '30')->first();
        app(PmsCatalogSync::class)->setAllowed($pms, true);
        $service = $pms->refresh()->service;
        $service->update(['name' => 'Завтрак (RU)']);

        $this->content = Http::response($this->catalog([['id' => '30', 'name' => 'Breakfast', 'kind' => 'Meal', 'status' => 'Active'], ['id' => '31', 'name' => 'Cable car', 'kind' => 'Common', 'status' => 'Active']]));
        $stats = app(PmsCatalogSync::class)->run();

        $this->assertSame(2, PmsService::count());
        $this->assertSame(2, $stats['updated']);
        $this->assertSame('Breakfast', $pms->refresh()->name);
        $this->assertSame('Завтрак (RU)', $service->refresh()->name, 'Название в Gifter не перезаписывается');
        $this->assertSame(1, Service::count());
    }

    public function test_toggle_creates_service_without_price_and_off_hides_it(): void
    {
        app(PmsCatalogSync::class)->run();
        $pms = PmsService::where('external_id', '31')->first();

        app(PmsCatalogSync::class)->setAllowed($pms, true);
        $service = $pms->refresh()->service;
        $this->assertSame('Канатная дорога', $service->name);
        $this->assertTrue($service->is_active);
        $this->assertSame(0, $service->prices()->count(), 'Цен из PMS нет');

        app(PmsCatalogSync::class)->setAllowed($pms->refresh(), false);
        $this->assertFalse($service->refresh()->is_active);
        app(PmsCatalogSync::class)->setAllowed($pms->refresh(), true);
        $this->assertTrue($service->refresh()->is_active);
        $this->assertSame(1, Service::count(), 'Повторное включение не создаёт дубль');
    }

    public function test_missing_service_is_flagged_not_deleted_and_hidden(): void
    {
        app(PmsCatalogSync::class)->run();
        $pms = PmsService::where('external_id', '31')->first();
        app(PmsCatalogSync::class)->setAllowed($pms, true);

        $this->content = Http::response($this->catalog([['id' => '30', 'name' => 'Завтрак', 'kind' => 'Meal', 'status' => 'Active']]));
        $stats = app(PmsCatalogSync::class)->run();

        $this->assertSame(1, $stats['missing']);
        $this->assertNotNull($pms->refresh()->missing_since);
        $this->assertFalse($pms->service->refresh()->is_active);
        try {
            app(PmsCatalogSync::class)->setAllowed($pms, true);
            $this->fail('Услуга пропала в PMS');
        } catch (DomainRuleViolation) {
        }

        $this->content = Http::response($this->catalog());
        app(PmsCatalogSync::class)->run();
        $this->assertNull($pms->refresh()->missing_since);
        $this->assertTrue($pms->service->refresh()->is_active, 'Вернулась в PMS — снова доступна');
    }

    public function test_access_denied_and_hub_provider_give_clear_messages(): void
    {
        $this->content = Http::response([], 403);
        try {
            app(PmsCatalogSync::class)->run();
            $this->fail('Content API без доступа');
        } catch (PmsException $e) {
            $this->assertStringContainsString('Content API', $e->getMessage());
        }
        $this->assertFalse(PmsCatalogSync::last()['ok']);

        PmsSetting::query()->update(['provider' => 'hub']);
        $this->expectException(PmsException::class);
        app(PmsCatalogSync::class)->run();
    }

    public function test_connection_failure_shows_the_reason_without_secrets_or_booking_wording(): void
    {
        Http::swap(new Factory);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds fictional-secret');
        });

        try {
            app(PmsCatalogSync::class)->run();
            $this->fail('Нет соединения');
        } catch (PmsException $e) {
            $this->assertStringContainsString('таймаут соединения: connect.hopenapi.com', $e->getMessage());
            $this->assertStringContainsString('connect.hopenapi.com:443', $e->getMessage());
            $this->assertStringNotContainsString('бронь', mb_strtolower($e->getMessage()));
            $this->assertStringNotContainsString('fictional-secret', $e->getMessage());
        }
        $this->assertSame(2, $attempts, 'Один повтор при сбое соединения');
    }

    public function test_dns_and_ssl_reasons(): void
    {
        foreach (['cURL error 6: Could not resolve host: connect.hopenapi.com' => 'DNS', 'cURL error 60: SSL certificate problem' => 'SSL'] as $error => $word) {
            Http::swap(new Factory);
            Http::fake(fn () => throw new ConnectionException($error));
            try {
                PmsClient::make()->catalogServices();
                $this->fail('Нет соединения');
            } catch (PmsException $e) {
                $this->assertStringContainsString($word, $e->getMessage());
            }
        }
    }

    public function test_ping_reports_content_api_access(): void
    {
        Http::swap(new Factory);
        Http::fake(fn ($request) => str_contains($request->url(), '/auth/token') ? Http::response(['access_token' => 't', 'expires_in' => 900])
            : (str_contains($request->url(), 'content') ? Http::response([], 403) : Http::response(['error' => 'not_found'], 404)));

        $result = PmsClient::make()->ping();

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Content API', $result['message']);
        $this->assertStringContainsString('нет доступа', $result['message']);
    }

    public function test_command_and_page(): void
    {
        $this->artisan('gifter:sync-pms-catalog')->expectsOutputToContain('новых: 2')->assertSuccessful();

        $this->actingAs(User::factory()->create(['roles' => [Role::SalesDirector]]));
        Livewire::test(PmsServices::class)->assertSee('Канатная дорога')->assertSee('ID 31');

        $this->actingAs(User::factory()->create(['roles' => [Role::FrontOffice]]));
        $this->get('/admin/pms-services')->assertForbidden();
    }

    public function test_existing_service_can_be_linked_instead_of_creating_a_duplicate(): void
    {
        app(PmsCatalogSync::class)->run();
        $existing = Service::factory()->create(['name' => 'Завтрак в ресторане']);
        $pms = PmsService::where('external_id', '30')->first();

        app(PmsCatalogSync::class)->link($pms, $existing);

        $this->assertSame($existing->id, $pms->refresh()->service_id);
        $this->assertTrue($pms->allowed_in_certificate);
        $this->assertSame(1, Service::count());
    }
}
