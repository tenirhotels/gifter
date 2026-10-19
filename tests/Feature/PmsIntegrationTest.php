<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\NameMatch;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Pages\PmsSettings;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Package;
use App\Models\PmsSetting;
use App\Models\Recipient;
use App\Models\Service;
use App\Models\User;
use App\Services\CertificateManager;
use App\Services\Pms\PmsClient;
use App\Services\Pms\PmsException;
use App\Services\ReservationManager;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Подтверждение брони по PMS через Hotel Hub (формат Exely): гость и даты берутся из PMS, без подтверждения бронь не сохраняется. */
class PmsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://hub.example.test/v1/properties/9305/bookings/*';

    private Certificate $certificate;

    /** Следующий ответ PMS: ответ или функция (для сбоя связи). */
    private mixed $next = null;

    /** Ответ Exely на запрос токена. */
    private mixed $auth = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->auth = Http::response(['access_token' => 'fictional-exely-token', 'expires_in' => 900]);
        Http::fake(function ($request) {
            $answer = str_contains($request->url(), '/auth/token') ? $this->auth : $this->next;

            return is_callable($answer) ? $answer() : $answer;
        });
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $service = Service::factory()->create(['name' => 'Проживание']);
        app(ServicePricing::class)->addPrice($service, '100000', Carbon::parse('2026-01-01'));
        $package = Package::create(['name' => 'Уикенд', 'validity_value' => 12, 'validity_unit' => 'months']);
        $package->items()->create(['service_id' => $service->id, 'quantity' => 1]);
        $manager = app(CertificateManager::class);
        $draft = $manager->createDraft([
            'package_id' => $package->id, 'customer_id' => Customer::factory()->create(['last_name' => 'Сидоров', 'first_name' => 'Пётр'])->id,
            'recipient_id' => Recipient::factory()->create(['last_name' => 'Иванова', 'first_name' => 'Анна'])->id,
        ]);
        DB::table('payments')->insert(['certificate_id' => $draft->id, 'method' => 'kaspi_invoice', 'status' => 'paid', 'amount' => 100000, 'currency' => 'KZT', 'invoiced_at' => now(), 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->certificate = $manager->issue($draft->refresh());
    }

    private function enable(): void
    {
        PmsSetting::create(['enabled' => true, 'base_url' => 'https://hub.example.test', 'property_code' => '9305', 'token' => 'fictional-token']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function booking(array $overrides = [], array $hub = ['data_age_minutes' => 3, 'stale' => false]): array
    {
        return ['booking' => array_replace_recursive([
            'propertyId' => '9305', 'number' => '20261020-9305-1', 'status' => 'Active',
            'roomStays' => [[
                'index' => 0, 'stayDates' => ['arrivalDateTime' => '2026-10-20T14:00', 'departureDateTime' => '2026-10-22T12:00'],
                'guests' => [['firstName' => 'Анна', 'lastName' => 'Иванова']], 'hub' => ['status' => 'booked'],
            ]],
            'customer' => ['firstName' => 'Пётр', 'lastName' => 'Сидоров'],
        ], $overrides), 'hub' => $hub];
    }

    public function test_booking_is_confirmed_by_pms_and_guest_with_dates_come_from_it(): void
    {
        $this->enable();
        $this->next = Http::response($this->booking());

        $this->actingAs(User::factory()->create(['roles' => [Role::FrontOffice]]));
        // Что бы ни ввели руками — берётся из PMS.
        app(ReservationManager::class)->save($this->certificate, ['booking_number' => '20261020-9305-1', 'guest_name' => 'Кто-то Другой', 'arrival' => '2026-12-01', 'departure' => '2026-12-02']);

        $c = $this->certificate->refresh();
        $this->assertSame([CertificateStatus::Reserved, 'Иванова Анна', '2026-10-20', '2026-10-22', NameMatch::Recipient], [$c->status, $c->booking_guest_name,
            $c->arrival_date->toDateString(), $c->departure_date->toDateString(), $c->name_match]);
        $this->assertNotNull($c->pms_verified_at);
        $this->assertSame(3, $c->pms_data_age_minutes);
        $this->assertTrue($c->history()->where('description', 'like', '%Подтверждена по PMS%')->exists());
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer fictional-token') && str_contains($request->url(), '/v1/properties/9305/bookings/20261020-9305-1'));
    }

    public function test_strict_without_confirmation_booking_is_not_saved(): void
    {
        $this->enable();
        $manager = app(ReservationManager::class);
        $cases = [
            'не найдена' => Http::response(['error' => 'not_found'], 404),
            'устарели' => Http::response(['error' => 'source_unavailable'], 503),
            'отклонила токен' => Http::response(['error' => 'forbidden'], 403),
            'Слишком много' => Http::response([], 429),
            'ошибку 500' => Http::response([], 500),
            'отменена в PMS' => Http::response($this->booking(['status' => 'Cancelled'])),
            'нет дат' => Http::response(['booking' => ['number' => 'X-1', 'status' => 'Active', 'roomStays' => [['guests' => [['firstName' => 'А', 'lastName' => 'Б']]]]]]),
        ];
        foreach ($cases as $expected => $response) {
            $this->next = $response;
            try {
                $manager->save($this->certificate, ['booking_number' => 'X-1']);
                $this->fail("Бронь сохранена при: {$expected}");
            } catch (PmsException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
            $this->assertSame(CertificateStatus::Active, $this->certificate->refresh()->status);
            $this->assertNull($this->certificate->pms_booking_number);
        }

        $this->next = fn () => throw new ConnectionException('timeout');
        $this->expectException(PmsException::class);
        $this->expectExceptionMessage('PMS не отвечает');
        $manager->save($this->certificate, ['booking_number' => 'X-1']);
    }

    public function test_multi_room_dates_and_cancelled_room_and_all_cancelled(): void
    {
        $this->enable();
        $stay = fn (string $from, string $to, string $status, string $first) => [
            'stayDates' => ['arrivalDateTime' => "{$from}T14:00", 'departureDateTime' => "{$to}T12:00"],
            'guests' => [['firstName' => $first, 'lastName' => 'Гость']], 'hub' => ['status' => $status]];
        $this->next = Http::response(['booking' => ['number' => 'M-1', 'status' => 'Active', 'roomStays' => [
            $stay('2026-10-21', '2026-10-23', 'booked', 'Один'),
            $stay('2026-10-20', '2026-10-24', 'booked', 'Два'),
            $stay('2026-09-01', '2026-09-02', 'cancelled', 'Отмена'),
        ]], 'hub' => ['stale' => false, 'data_age_minutes' => 1]]);

        $booking = PmsClient::make()->find('M-1');
        $this->assertSame(['2026-10-20', '2026-10-24', 2], [$booking->arrival->toDateString(), $booking->departure->toDateString(), $booking->roomCount]);
        $this->assertNotContains('Гость Отмена', $booking->guestNames);

        $this->next = Http::response(['booking' => ['number' => 'M-2', 'status' => 'Active', 'roomStays' => [$stay('2026-10-21', '2026-10-23', 'cancelled', 'Один')]]]);
        $this->assertTrue(PmsClient::make()->find('M-2')->cancelled, 'Все комнаты отменены — бронь отменена');
    }

    public function test_name_match_prefers_recipient_then_customer_else_mismatch_needs_note(): void
    {
        $this->enable();
        $manager = app(ReservationManager::class);
        $guests = fn (array $people) => $this->booking(['roomStays' => [['guests' => $people]], 'customer' => ['firstName' => 'Кто', 'lastName' => 'Угодно']]);

        $this->next = Http::response($guests([['firstName' => 'Иван', 'lastName' => 'Левый'], ['firstName' => 'Анна', 'lastName' => 'Иванова']]));
        $this->assertSame([NameMatch::Recipient, 'Иванова Анна'], [($r = $manager->resolveFromPms($this->certificate, 'B'))['match'], $r['guest']]);

        $this->next = Http::response($guests([['firstName' => 'Пётр', 'lastName' => 'Сидоров']]));
        $this->assertSame(NameMatch::Customer, $manager->resolveFromPms($this->certificate, 'B2')['match']);

        $this->next = Http::response($guests([['firstName' => 'Иван', 'lastName' => 'Левый']]));
        try {
            $manager->save($this->certificate, ['booking_number' => 'B3']);
            $this->fail('Несовпадение имени без причины');
        } catch (DomainRuleViolation $e) {
            $this->assertStringContainsString('причину', $e->getMessage());
        }
        $manager->save($this->certificate, ['booking_number' => 'B3', 'note' => 'Гость придёт вместо получателя']);
        $this->assertSame(NameMatch::Mismatch, $this->certificate->refresh()->name_match);
        $this->assertNotNull($this->certificate->reservation_review_status, 'Несовпадение уходит на проверку Финансов');
    }

    public function test_stale_data_is_accepted_but_marked(): void
    {
        $this->enable();
        $this->next = Http::response($this->booking(hub: ['data_age_minutes' => 240, 'stale' => true]));

        app(ReservationManager::class)->save($this->certificate, ['booking_number' => 'S-1']);

        $this->assertSame(240, $this->certificate->refresh()->pms_data_age_minutes);
        $this->assertTrue($this->certificate->history()->where('description', 'like', '%данные устарели: 240 мин%')->exists());
    }

    public function test_without_integration_manual_entry_still_works(): void
    {
        app(ReservationManager::class)->save($this->certificate, ['booking_number' => 'M-1', 'guest_name' => 'Иванова Анна', 'arrival' => '2026-10-20', 'departure' => '2026-10-22']);

        $this->assertSame(CertificateStatus::Reserved, $this->certificate->refresh()->status);
        $this->assertNull($this->certificate->pms_verified_at);
        Http::assertNothingSent();
    }

    public function test_reserve_action_asks_only_for_the_number_and_shows_pms_data(): void
    {
        $this->enable();
        $this->next = Http::response($this->booking());
        $this->actingAs(User::factory()->create(['roles' => [Role::FrontOffice]]));

        Livewire::test(ViewCertificate::class, ['record' => $this->certificate->id])
            ->mountAction('reserve')
            ->fillForm(['booking_number' => '20261020-9305-1'])
            ->assertMountedActionModalSee(['Иванова Анна', '20.10.2026', 'Совпадает с получателем'])
            ->callMountedAction()->assertHasNoActionErrors();

        $this->assertSame('Иванова Анна', $this->certificate->refresh()->booking_guest_name);
    }

    public function test_settings_page_admin_only_token_encrypted_and_not_shown_or_audited(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::Finance]]));
        $this->get('/admin/pms-settings')->assertForbidden();

        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $page = Livewire::test(PmsSettings::class)
            ->fillForm(['enabled' => true, 'base_url' => 'https://hub.example.test/', 'property_code' => '9305', 'token' => 'fictional-secret-token'])
            ->call('save');

        $raw = DB::table('pms_settings')->first();
        $this->assertSame('https://hub.example.test', $raw->base_url);
        $this->assertStringNotContainsString('fictional-secret-token', (string) $raw->token);
        $this->assertSame('fictional-secret-token', PmsSetting::current()->token);
        $this->assertStringNotContainsString('fictional-secret-token', AuditLog::all()->toJson());
        $this->assertTrue(PmsSetting::current()->isActive());

        $page->assertDontSee('fictional-secret-token');
        $this->get('/admin/pms-settings')->assertOk()->assertDontSee('fictional-secret-token');

        // Пустой токен не затирает сохранённый; без токена включить нельзя.
        Livewire::test(PmsSettings::class)->fillForm(['property_code' => '9306', 'token' => ''])->call('save');
        $this->assertSame(['9306', 'fictional-secret-token'], [PmsSetting::current()->property_code, PmsSetting::current()->token]);
        PmsSetting::query()->delete();
        Livewire::test(PmsSettings::class)->fillForm(['enabled' => true, 'base_url' => 'https://x.test', 'property_code' => '1', 'token' => ''])->call('save');
        $this->assertSame(0, PmsSetting::count());
    }

    public function test_ping_distinguishes_connection_and_token(): void
    {
        $this->enable();
        $this->next = Http::response(['error' => 'not_found'], 404);
        $this->assertTrue(PmsClient::make()->ping()['ok']);

        $this->next = Http::response(['error' => 'forbidden'], 403);
        $result = PmsClient::make()->ping();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('токен', $result['message']);
    }

    private function enableExely(): void
    {
        PmsSetting::query()->delete();
        PmsSetting::create(['enabled' => true, 'provider' => 'exely', 'property_code' => '9305', 'client_id' => 'fictional-client', 'client_secret' => 'fictional-secret']);
    }

    public function test_native_exely_uses_oauth_token_cached_and_read_reservation_path(): void
    {
        $this->enableExely();
        $this->next = Http::response($this->booking(hub: []));
        Cache::flush();

        $client = PmsClient::make();
        $this->assertSame('2026-10-20', $client->find('20261020-9305-1')->arrival->toDateString());
        $client->find('20261020-9305-2');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'https://connect.hopenapi.com/auth/token') && $r['grant_type'] === 'client_credentials'
            && $r['client_id'] === 'fictional-client' && $r['client_secret'] === 'fictional-secret');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'https://connect.hopenapi.com/api/read-reservation/v1/properties/9305/bookings/20261020-9305-1')
            && $r->hasHeader('Authorization', 'Bearer fictional-exely-token'));
        $tokenRequests = Http::recorded(fn ($request) => str_contains($request->url(), '/auth/token'))->count();
        $this->assertSame(1, $tokenRequests, 'Токен берётся один раз и кешируется');
    }

    public function test_native_exely_saves_reservation_and_marks_no_staleness(): void
    {
        $this->enableExely();
        $this->next = Http::response($this->booking(hub: []));

        app(ReservationManager::class)->save($this->certificate, ['booking_number' => '20261020-9305-1']);

        $c = $this->certificate->refresh();
        $this->assertSame(['Иванова Анна', NameMatch::Recipient, null], [$c->booking_guest_name, $c->name_match, $c->pms_data_age_minutes]);
        $this->assertNotNull($c->pms_verified_at);
    }

    public function test_native_exely_errors_keys_rejected_expired_token_and_not_found(): void
    {
        $this->enableExely();
        Cache::flush();

        $this->auth = Http::response(['error' => 'invalid_client'], 401);
        try {
            PmsClient::make()->find('X-1');
            $this->fail('Ключи отклонены');
        } catch (PmsException $e) {
            $this->assertStringContainsString('отклонил ключи', $e->getMessage());
        }

        $this->auth = Http::response(['access_token' => 'fictional-exely-token', 'expires_in' => 900]);
        $this->next = Http::response(['error' => 'forbidden'], 403);
        try {
            PmsClient::make()->find('X-1');
            $this->fail('Нет доступа к Read Reservation API');
        } catch (PmsException $e) {
            $this->assertStringContainsString('Read Reservation', $e->getMessage());
        }

        $this->next = Http::response([], 404);
        try {
            PmsClient::make()->find('X-1');
            $this->fail('Не найдена');
        } catch (PmsException $e) {
            $this->assertStringContainsString('не найдена', $e->getMessage());
        }
        $this->assertTrue(PmsClient::make()->ping()['ok']);

        // Токен протух раньше срока: 401 на брони — один новый токен и повтор.
        Cache::flush();
        $calls = 0;
        $this->next = function () use (&$calls) {
            return ++$calls === 1 ? Http::response([], 401) : Http::response($this->booking(hub: []));
        };
        $this->assertSame('2026-10-22', PmsClient::make()->find('20261020-9305-1')->departure->toDateString());
        $this->assertSame(2, $calls);
    }

    public function test_provider_is_selected_in_settings_and_secrets_are_encrypted(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        Livewire::test(PmsSettings::class)
            ->fillForm(['enabled' => true, 'provider' => 'exely', 'property_code' => '9305', 'client_id' => 'fictional-client', 'client_secret' => 'fictional-exely-secret'])
            ->call('save');

        $setting = PmsSetting::current();
        $this->assertSame(['exely', 'https://connect.hopenapi.com', true], [$setting->provider, $setting->baseUrl(), $setting->isActive()]);
        $this->assertStringNotContainsString('fictional-exely-secret', (string) DB::table('pms_settings')->value('client_secret'));
        $this->assertStringNotContainsString('fictional-exely-secret', AuditLog::all()->toJson());
        $this->get('/admin/pms-settings')->assertOk()->assertDontSee('fictional-exely-secret');

        // Без client_secret включить нельзя; смена на хаб требует адреса и токена.
        PmsSetting::query()->delete();
        Livewire::test(PmsSettings::class)->fillForm(['enabled' => true, 'provider' => 'exely', 'property_code' => '9305', 'client_id' => 'x', 'client_secret' => ''])->call('save');
        $this->assertSame(0, PmsSetting::count());
        Livewire::test(PmsSettings::class)->fillForm(['enabled' => true, 'provider' => 'hub', 'base_url' => 'https://hub.example.test', 'property_code' => '9305', 'token' => ''])->call('save');
        $this->assertSame(0, PmsSetting::count());
    }

    public function test_ping_uses_a_number_in_pms_format_and_accepts_format_error_as_reached(): void
    {
        $this->enable();
        $this->next = Http::response(['error' => 'not_found'], 404);
        PmsClient::make()->ping();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/bookings/20000101-9305-1'));

        $this->next = Http::response(['error' => 'bad_request'], 400);
        $this->assertTrue(PmsClient::make()->ping()['ok'], 'Ответ получен после проверки токена — связь есть');
    }
}
