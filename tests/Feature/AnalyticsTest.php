<?php

namespace Tests\Feature;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\HistoryEvent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Filament\Pages\Analytics;
use App\Filament\Widgets\Analytics\AnalyticsCohortsTable;
use App\Filament\Widgets\Analytics\AnalyticsFlowChart;
use App\Filament\Widgets\Analytics\AnalyticsNotice;
use App\Filament\Widgets\Analytics\AnalyticsPackagesChart;
use App\Filament\Widgets\Analytics\AnalyticsStats;
use App\Filament\Widgets\Analytics\AnalyticsYearsTable;
use App\Models\Certificate;
use App\Models\Package;
use App\Models\Payment;
use App\Models\User;
use App\Services\AnalyticsReport;
use App\Support\AnalyticsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** «Аналитика»: продажа, оплата и использование по месяцам и годам; бесплатные; популярные пакеты. */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-25 12:00:00');
        Cache::flush(); // отчёт кэшируется на минуту: в тестах время заморожено
        $this->package = Package::create(['name' => 'Stay for two', 'validity_value' => 2, 'validity_unit' => 'months']);
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
    }

    private function certificate(array $attributes = []): Certificate
    {
        $n = Certificate::count() + 1;

        return Certificate::create($attributes + [
            'number' => "TST-{$n}", 'verification_token' => str()->random(32), 'package_id' => $this->package->id, 'status' => CertificateStatus::Active, 'kind' => CertificateKind::Sale,
            'sold_at' => '2025-03-10', 'valid_from' => '2025-03-10', 'valid_until' => '2025-06-10', 'custom_validity' => true,
            'total_amount' => '100000', 'payable_amount' => '100000', 'currency' => 'KZT', 'issued_at' => '2025-03-10', 'source' => 'staff',
        ]);
    }

    private function pay(Certificate $certificate, string $paidAt, string $amount = '100000'): void
    {
        Payment::create(['certificate_id' => $certificate->id, 'method' => PaymentMethod::Cash, 'status' => PaymentStatus::Paid, 'amount' => $amount, 'currency' => 'KZT', 'invoiced_at' => $paidAt, 'paid_at' => $paidAt]);
    }

    public function test_sales_payments_and_usage_are_counted_by_their_own_dates(): void
    {
        $sold = $this->certificate(['sold_at' => '2025-03-10']);
        $this->pay($sold, '2025-04-02 10:00:00');
        $used = $this->certificate(['status' => CertificateStatus::Used, 'sold_at' => '2025-05-01', 'arrival_date' => '2025-07-15', 'total_amount' => '200000']);
        $viaHistory = $this->certificate(['status' => CertificateStatus::Used, 'sold_at' => '2025-05-02', 'total_amount' => '50000']);
        $viaHistory->history()->create(['event' => HistoryEvent::StatusChanged, 'before' => ['status' => 'active'], 'after' => ['status' => 'used'], 'description' => 'использован', 'created_at' => '2025-08-20 09:00:00']);
        $this->certificate(['status' => CertificateStatus::Used, 'sold_at' => '2024-01-05', 'total_amount' => '70000']); // дата использования неизвестна

        $r = app(AnalyticsReport::class)->build(AnalyticsPeriod::resolve('2025'));

        $this->assertSame(['sold' => 100000.0 + 200000.0 + 50000.0, 'sold_count' => 3], ['sold' => $r['totals']['sold'], 'sold_count' => $r['totals']['sold_count']]);
        $this->assertSame(100000.0, $r['sold'][2], 'Март — индекс 2');
        $this->assertSame(100000.0, $r['paid'][3], 'Оплата в апреле');
        $this->assertSame(200000.0, $r['used'][6], 'Использование по заезду — июль');
        $this->assertSame(50000.0, $r['used'][7], 'Использование по истории — август');
        $this->assertSame(2, $r['totals']['used_count']);

        $all = app(AnalyticsReport::class)->build(AnalyticsPeriod::resolve('all', app(AnalyticsReport::class)->years()));
        $this->assertSame(['2024', '2025', '2026'], array_slice($all['labels'], 0, 3), 'Больше 24 месяцев — ряды по годам');
        $this->assertSame(1, $all['totals']['used_unknown_date']);
        $this->assertSame(3, $all['totals']['used_count']);
    }

    public function test_free_cancelled_and_refunds_are_separate(): void
    {
        $this->certificate(['kind' => CertificateKind::Complimentary, 'total_amount' => '0', 'payable_amount' => '0', 'marketing_cost' => '15000']);
        $this->certificate(['status' => CertificateStatus::Cancelled]);
        $this->certificate();

        $r = app(AnalyticsReport::class)->build(AnalyticsPeriod::resolve('2025'));

        $this->assertSame(1, $r['totals']['free_count']);
        $this->assertSame(15000.0, $r['totals']['free_cost']);
        $this->assertSame(1, $r['totals']['cancelled']);
        $this->assertSame(1, $r['totals']['sold_count']);
        $this->assertSame('Stay for two', $r['packages'][0]['name']);
    }

    public function test_page_is_available_to_director_and_finance_but_not_to_sales(): void
    {
        $this->certificate();

        foreach ([Role::SalesDirector, Role::Finance, Role::SuperAdmin] as $role) {
            $this->actingAs(User::factory()->create(['roles' => [$role]]));
            Livewire::test(Analytics::class)->assertSuccessful();
        }
        $this->actingAs(User::factory()->create(['roles' => [Role::Sales]]));
        $this->assertFalse(Analytics::canAccess());
        $this->actingAs(User::factory()->create(['roles' => [Role::FrontOffice]]));
        $this->assertFalse(Analytics::canAccess());
    }

    public function test_page_renders_widgets_over_http(): void
    {
        $this->certificate();

        $this->get('/admin/analytics')->assertOk()->assertSee('Аналитика');
        Livewire::test(AnalyticsStats::class)->assertSee('Продано')->assertSee('Оплачено')->assertSee('Использовано')->assertSee('Бесплатных');
        Livewire::test(AnalyticsFlowChart::class)->assertSee('Продажа, оплата и использование');
        Livewire::test(AnalyticsPackagesChart::class)->assertSee('Популярные пакеты');
        Livewire::test(AnalyticsYearsTable::class)->assertSee('По годам')->assertSee('2025');
    }

    public function test_year_filter_changes_the_period(): void
    {
        $this->certificate(['sold_at' => '2024-02-02']);
        $this->certificate(['sold_at' => '2025-02-02']);

        Livewire::test(Analytics::class)->set('filters.period', '2024')->assertSuccessful();
        $this->assertCount(12, app(AnalyticsReport::class)->build(AnalyticsPeriod::resolve('2024'))['labels']);
        $this->assertSame([2024, 2025], app(AnalyticsReport::class)->years());
    }

    public function test_period_presets_previous_period_and_buckets(): void
    {
        $this->assertSame(['2026-01', '2026-12'], [array_key_first(AnalyticsPeriod::resolve('this_year')->buckets()), array_key_last(AnalyticsPeriod::resolve('this_year')->buckets())]);
        $last = AnalyticsPeriod::resolve('last_year');
        $this->assertSame('2025-01-01', $last->from->toDateString());
        $this->assertSame('2024-01-01', $last->previous()->from->toDateString());
        $this->assertSame('2024-12-31', $last->previous()->to->toDateString());
        $twelve = AnalyticsPeriod::resolve('last_12');
        $this->assertCount(12, $twelve->buckets());
        $this->assertSame('2025-11', array_key_first($twelve->buckets()));
        $this->assertNull(AnalyticsPeriod::resolve('all', [2021, 2026])->previous());
        $this->assertFalse(AnalyticsPeriod::resolve('all', [2021, 2026])->monthly);
    }

    public function test_outstanding_cohorts_and_average_days(): void
    {
        $this->certificate(['sold_at' => '2026-09-01', 'total_amount' => '120000']); // действует
        $this->certificate(['status' => CertificateStatus::Expired, 'sold_at' => '2025-01-10']);
        $this->certificate(['status' => CertificateStatus::Used, 'sold_at' => '2025-02-01', 'arrival_date' => '2025-03-03']);
        $service = app(AnalyticsReport::class);

        $this->assertSame(['count' => 1, 'sum' => 120000.0], $service->outstanding());
        $this->assertSame(30.0, $service->averageDaysToUse());
        $cohort = collect($service->cohorts())->firstWhere('year', 2025);
        $this->assertSame([2, 1, 1, 0], [$cohort['count'], $cohort['used'], $cohort['expired'], $cohort['active']]);
        $this->assertSame(50.0, $cohort['used_pct']);
        $this->assertCount(12, $service->seasonality());
    }

    public function test_empty_period_shows_the_empty_state_and_comparison_has_deltas(): void
    {
        Livewire::test(AnalyticsNotice::class)->assertSee('Нет данных за период');

        $this->certificate(['sold_at' => '2025-05-01', 'total_amount' => '100000']);
        $this->certificate(['sold_at' => '2026-05-01', 'total_amount' => '150000']);
        Livewire::test(AnalyticsStats::class, ['pageFilters' => ['period' => 'this_year', 'compare' => true]])->assertSee('+50,0%');
        Livewire::test(AnalyticsStats::class, ['pageFilters' => ['period' => 'this_year', 'compare' => false]])->assertDontSee('к прошлому периоду');
        Livewire::test(AnalyticsCohortsTable::class)->assertSee('2026');
    }

    public function test_csv_export_has_a_row_per_period_bucket(): void
    {
        $this->certificate(['sold_at' => '2026-05-01', 'total_amount' => '150000']);

        $response = Livewire::test(Analytics::class)->set('filters.period', 'this_year')->callAction('export');

        $response->assertFileDownloaded();
    }
}
