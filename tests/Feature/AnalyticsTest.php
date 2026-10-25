<?php

namespace Tests\Feature;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\HistoryEvent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Filament\Pages\Analytics;
use App\Filament\Widgets\Analytics\AnalyticsFlowChart;
use App\Filament\Widgets\Analytics\AnalyticsPackagesChart;
use App\Filament\Widgets\Analytics\AnalyticsStats;
use App\Filament\Widgets\Analytics\AnalyticsYearsTable;
use App\Models\Certificate;
use App\Models\Package;
use App\Models\Payment;
use App\Models\User;
use App\Services\AnalyticsReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

        $r = app(AnalyticsReport::class)->build(2025);

        $this->assertSame(['sold' => 100000.0 + 200000.0 + 50000.0, 'sold_count' => 3], ['sold' => $r['totals']['sold'], 'sold_count' => $r['totals']['sold_count']]);
        $this->assertSame(100000.0, $r['sold'][2], 'Март — индекс 2');
        $this->assertSame(100000.0, $r['paid'][3], 'Оплата в апреле');
        $this->assertSame(200000.0, $r['used'][6], 'Использование по заезду — июль');
        $this->assertSame(50000.0, $r['used'][7], 'Использование по истории — август');
        $this->assertSame(2, $r['totals']['used_count']);

        $all = app(AnalyticsReport::class)->build(null);
        $this->assertSame([2024, 2025], array_map('intval', $all['labels']));
        $this->assertSame(1, $all['totals']['used_unknown_date']);
        $this->assertSame(3, $all['totals']['used_count']);
    }

    public function test_free_cancelled_and_refunds_are_separate(): void
    {
        $this->certificate(['kind' => CertificateKind::Complimentary, 'total_amount' => '0', 'payable_amount' => '0', 'marketing_cost' => '15000']);
        $this->certificate(['status' => CertificateStatus::Cancelled]);
        $this->certificate();

        $r = app(AnalyticsReport::class)->build(2025);

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

        Livewire::test(Analytics::class)->set('filters.year', 2024)->assertSuccessful();
        $this->assertCount(12, app(AnalyticsReport::class)->build(2024)['labels']);
        $this->assertSame([2024, 2025], app(AnalyticsReport::class)->years());
    }
}
