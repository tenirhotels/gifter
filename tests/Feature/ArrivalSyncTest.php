<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\ReservationReview;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Package;
use App\Models\PmsSetting;
use App\Models\Recipient;
use App\Models\Service;
use App\Models\User;
use App\Services\ArrivalSync;
use App\Services\CertificateManager;
use App\Services\ReservationManager;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Заезд по данным PMS: автопроверка в день заезда (мало запросов) и кнопка «Заезд» с проверкой. */
class ArrivalSyncTest extends TestCase
{
    use RefreshDatabase;

    private mixed $next = null;

    private int $requests = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 08:00:00');
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/bookings/')) {
                $this->requests++;
            }

            return is_callable($this->next) ? ($this->next)() : $this->next;
        });
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        PmsSetting::create(['enabled' => true, 'base_url' => 'https://hub.example.test', 'property_code' => '9305', 'token' => 'fictional-token']);
        $this->next = Http::response($this->booking());
    }

    /** @param  array<string, mixed>  $stay */
    private function booking(array $stay = [], string $status = 'booked', string $number = '20261020-9305-661'): array
    {
        return ['booking' => [
            'number' => $number, 'status' => 'Active',
            'roomStays' => [array_replace_recursive([
                'stayDates' => ['arrivalDateTime' => '2026-10-20T14:00', 'departureDateTime' => '2026-10-22T12:00'],
                'ratePlans' => [['id' => 'r1', 'name' => 'Сертификат']],
                'guests' => [['firstName' => 'Анна', 'lastName' => 'Иванова']], 'hub' => ['status' => $status],
            ], $stay)],
            'customer' => ['firstName' => 'Пётр', 'lastName' => 'Сидоров'],
        ], 'hub' => ['stale' => false, 'data_age_minutes' => 2]];
    }

    private function reserved(string $number = '20261020-9305-661'): Certificate
    {
        $service = Service::factory()->create(['name' => 'Проживание', 'unit' => 'ночь']);
        app(ServicePricing::class)->addPrice($service, '100000', Carbon::parse('2026-01-01'));
        $package = Package::create(['name' => 'Уикенд', 'validity_value' => 12, 'validity_unit' => 'months']);
        $package->items()->create(['service_id' => $service->id, 'quantity' => 2]);
        $manager = app(CertificateManager::class);
        $draft = $manager->createDraft(['package_id' => $package->id, 'customer_id' => Customer::factory()->create(['last_name' => 'Сидоров', 'first_name' => 'Пётр'])->id,
            'recipient_id' => Recipient::factory()->create(['last_name' => 'Иванова', 'first_name' => 'Анна'])->id]);
        DB::table('payments')->insert(['certificate_id' => $draft->id, 'method' => 'kaspi_invoice', 'status' => 'paid', 'amount' => 200000, 'currency' => 'KZT', 'invoiced_at' => now(), 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $certificate = $manager->issue($draft->refresh());
        app(ReservationManager::class)->save($certificate, ['booking_number' => $number]);

        return $certificate->refresh();
    }

    public function test_hub_checked_in_marks_arrival_automatically_in_the_afternoon(): void
    {
        $certificate = $this->reserved();
        $this->requests = 0;

        $morning = app(ArrivalSync::class)->syncToday('morning');
        $this->assertSame(['checked' => 1, 'checked_in' => 0, 'discrepancies' => 0, 'unavailable' => 0], $morning);
        $this->assertSame(CertificateStatus::Reserved, $certificate->refresh()->status, 'Утром гость ещё не заселён');
        $this->assertSame('ok', $certificate->pms_check_status);

        Carbon::setTestNow('2026-10-20 16:00:00');
        $this->next = Http::response($this->booking(status: 'checked_in'));
        $afternoon = app(ArrivalSync::class)->syncToday('afternoon');

        $this->assertSame(1, $afternoon['checked_in']);
        $this->assertSame(CertificateStatus::InUse, $certificate->refresh()->status);
        $this->assertTrue($certificate->history()->where('description', 'like', '%Заезд по данным PMS%')->exists());
        $this->assertSame(2, $this->requests, 'Два запроса: утром и в 16:00');
    }

    private function inUse(): Certificate
    {
        $certificate = $this->reserved();
        Carbon::setTestNow('2026-10-20 16:00:00');
        $this->next = Http::response($this->booking(status: 'checked_in'));
        app(ArrivalSync::class)->checkIn($certificate);
        $this->assertSame(CertificateStatus::InUse, $certificate->refresh()->status);
        $this->requests = 0;

        return $certificate;
    }

    public function test_hub_checked_out_redeems_automatically(): void
    {
        $certificate = $this->inUse();

        Carbon::setTestNow('2026-10-22 14:00:00');
        $this->next = Http::response($this->booking(status: 'checked_in'));
        $this->assertSame(0, app(ArrivalSync::class)->syncDepartures()['redeemed'], 'Ещё не выехал');
        $this->assertSame(CertificateStatus::InUse, $certificate->refresh()->status);

        Carbon::setTestNow('2026-10-22 17:00:00');
        $this->next = Http::response($this->booking(status: 'checked_out'));
        $stats = app(ArrivalSync::class)->syncDepartures();

        $this->assertSame(1, $stats['redeemed']);
        $this->assertSame(CertificateStatus::Used, $certificate->refresh()->status);
        $this->assertTrue($certificate->history()->where('description', 'like', '%Погашен автоматически%')->exists());
    }

    public function test_hub_cancelled_booking_is_not_redeemed(): void
    {
        $certificate = $this->inUse();
        Carbon::setTestNow('2026-10-22 14:00:00');
        $booking = $this->booking(status: 'checked_out');
        $booking['booking']['status'] = 'Cancelled';
        $this->next = Http::response($booking);

        $stats = app(ArrivalSync::class)->syncDepartures();

        $this->assertSame([0, 1], [$stats['redeemed'], $stats['discrepancies']]);
        $this->assertSame(CertificateStatus::InUse, $certificate->refresh()->status);
    }

    public function test_direct_exely_redeems_the_day_after_planned_departure(): void
    {
        $certificate = $this->inUse();
        PmsSetting::first()->update(['provider' => 'exely', 'client_id' => 'c', 'client_secret' => 's', 'base_url' => null, 'token' => null]);
        $booking = $this->booking();
        unset($booking['booking']['roomStays'][0]['hub'], $booking['hub']);
        Http::swap(new Factory);
        Http::fake(fn ($request) => str_contains($request->url(), '/auth/token')
            ? Http::response(['access_token' => 't', 'expires_in' => 900]) : Http::response($booking));

        Carbon::setTestNow('2026-10-22 14:00:00');
        $this->assertSame(0, app(ArrivalSync::class)->syncDepartures()['checked'], 'В день выезда Exely не опрашиваем');

        Carbon::setTestNow('2026-10-23 14:00:00');
        $stats = app(ArrivalSync::class)->syncDepartures();

        $this->assertSame(1, $stats['redeemed']);
        $this->assertSame(CertificateStatus::Used, $certificate->refresh()->status);
    }

    public function test_direct_exely_has_no_checkin_status_so_nothing_is_marked_automatically(): void
    {
        $certificate = $this->reserved();
        PmsSetting::first()->update(['provider' => 'exely', 'client_id' => 'c', 'client_secret' => 's', 'base_url' => null, 'token' => null]);
        // У Exely нет блока hub: статуса комнаты в ответе нет.
        $booking = $this->booking();
        unset($booking['booking']['roomStays'][0]['hub'], $booking['hub']);
        $this->next = Http::response($booking);
        Http::fake(fn ($request) => str_contains($request->url(), '/auth/token')
            ? Http::response(['access_token' => 't', 'expires_in' => 900]) : Http::response($booking));

        Carbon::setTestNow('2026-10-20 16:00:00');
        $stats = app(ArrivalSync::class)->syncToday('afternoon');

        $this->assertSame(0, $stats['checked_in']);
        $this->assertSame(CertificateStatus::Reserved, $certificate->refresh()->status);
    }

    public function test_button_checks_pms_without_comment_and_marks_arrival(): void
    {
        $certificate = $this->reserved();
        $this->actingAs(User::factory()->create(['roles' => [Role::FrontOffice]]));
        $this->requests = 0;

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->mountAction('checkIn')
            ->assertMountedActionModalSee('запросит бронь № 20261020-9305-661 в PMS')
            ->callMountedAction()->assertHasNoActionErrors();

        $this->assertSame(CertificateStatus::InUse, $certificate->refresh()->status);
        $this->assertSame(1, $this->requests);
    }

    public function test_cancelled_in_pms_blocks_arrival_and_goes_to_finance_review(): void
    {
        $certificate = $this->reserved();
        $this->next = Http::response(['booking' => ['number' => '20261020-9305-661', 'status' => 'Cancelled', 'roomStays' => [['hub' => ['status' => 'cancelled']]]]]);

        try {
            app(ArrivalSync::class)->checkIn($certificate);
            $this->fail('Заезд по отменённой брони');
        } catch (DomainRuleViolation $e) {
            $this->assertStringContainsString('отменена в PMS', $e->getMessage());
        }

        $certificate->refresh();
        $this->assertSame([CertificateStatus::Reserved, 'cancelled', ReservationReview::Pending], [$certificate->status, $certificate->pms_check_status, $certificate->reservation_review_status]);
        $this->assertStringContainsString('Бронь отменена в PMS', (string) $certificate->reservation_note);
        $this->assertTrue($certificate->history()->where('description', 'like', '%На проверке Финансов%')->exists());

        // Решённая проверка не опрашивается повторно.
        $this->requests = 0;
        Carbon::setTestNow('2026-10-20 19:00:00');
        app(ArrivalSync::class)->syncToday('evening');
        $this->assertSame(0, $this->requests);
    }

    public function test_changed_dates_are_updated_from_pms_and_conditions_violation_goes_to_review(): void
    {
        $certificate = $this->reserved();

        // Даты сдвинулись, условия соблюдены: обновлено по PMS, без проверки Финансов.
        $this->next = Http::response($this->booking(['stayDates' => ['arrivalDateTime' => '2026-10-21T14:00', 'departureDateTime' => '2026-10-23T12:00']]));
        $result = app(ArrivalSync::class)->inspect($certificate);
        $this->assertSame('changed', $result['status']);
        $certificate->refresh();
        $this->assertSame(['2026-10-21', '2026-10-23'], [$certificate->arrival_date->toDateString(), $certificate->departure_date->toDateString()]);
        $this->assertNull($certificate->reservation_review_status);
        $this->assertTrue($certificate->history()->where('description', 'like', '%Даты изменены в PMS%')->exists());

        // Ночей стало больше, чем в сертификате: расхождение, на проверку.
        $this->next = Http::response($this->booking(['stayDates' => ['arrivalDateTime' => '2026-10-21T14:00', 'departureDateTime' => '2026-10-25T12:00']]));
        $this->assertSame('discrepancy', app(ArrivalSync::class)->inspect($certificate->refresh())['status']);
        $this->assertSame(ReservationReview::Pending, $certificate->refresh()->reservation_review_status);
        $this->assertStringContainsString('даёт 2 ночи', (string) $certificate->pms_check_note);
    }

    public function test_pms_unavailable_changes_nothing(): void
    {
        $certificate = $this->reserved();
        $this->next = fn () => throw new ConnectionException('timeout');
        Carbon::setTestNow('2026-10-20 16:00:00');

        $stats = app(ArrivalSync::class)->syncToday('afternoon');

        $this->assertSame(1, $stats['unavailable']);
        $certificate->refresh();
        $this->assertSame([CertificateStatus::Reserved, 'unavailable', null], [$certificate->status, $certificate->pms_check_status, $certificate->reservation_review_status]);
    }

    public function test_few_requests_only_today_one_per_booking_and_no_repeat_within_two_hours(): void
    {
        $this->reserved('20261020-9305-661');
        $second = $this->reserved('20261020-9305-663'); // два сертификата одной брони (как в группе): один запрос на номер
        $second->update(['pms_booking_number' => '20261020-9305-661']);
        // Бронь с заездом не сегодня — не опрашивается.
        $future = $this->reserved('20261020-9305-662');
        $future->update(['arrival_date' => '2026-11-10', 'departure_date' => '2026-11-12']);
        $this->requests = 0;

        $stats = app(ArrivalSync::class)->syncToday('afternoon');
        $this->assertSame(1, $this->requests, 'Один запрос на номер брони, заезд не сегодня — без запроса');
        $this->assertSame(2, $stats['checked']);

        // Повтор через час — без запросов.
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->requests = 0;
        app(ArrivalSync::class)->syncToday('afternoon');
        $this->assertSame(0, $this->requests);

        // Через два часа и больше — снова можно.
        Carbon::setTestNow('2026-10-20 11:30:00');
        app(ArrivalSync::class)->syncToday('afternoon');
        $this->assertSame(1, $this->requests);
    }

    public function test_early_arrival_message_without_request_and_manual_flow_without_pms(): void
    {
        $certificate = $this->reserved();
        $certificate->update(['arrival_date' => '2026-11-06', 'departure_date' => '2026-11-08']);
        $this->requests = 0;

        try {
            app(ArrivalSync::class)->checkIn($certificate);
            $this->fail('Раньше даты заезда');
        } catch (DomainRuleViolation $e) {
            $this->assertStringContainsString('с 06.11.2026', $e->getMessage());
        }
        $this->assertSame(0, $this->requests, 'Раньше срока в PMS не ходим');

        // Бронь внесена вручную (не подтверждена по PMS) — прежнее поведение с комментарием.
        $manual = $this->reserved('20261020-9305-669');
        $manual->update(['pms_verified_at' => null]);
        $this->actingAs(User::factory()->create(['roles' => [Role::FrontOffice]]));
        Livewire::test(ViewCertificate::class, ['record' => $manual->id])
            ->callAction('checkIn', ['reason' => 'Гость на ресепшене'])->assertHasNoActionErrors();
        $this->assertSame(CertificateStatus::InUse, $manual->refresh()->status);
    }
}
