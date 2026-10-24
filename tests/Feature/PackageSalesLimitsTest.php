<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Service;
use App\Models\User;
use App\Services\CertificateManager;
use App\Services\PackageSales;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Необязательные ограничения продажи пакета: лимит по числу (все каналы) и окно продаж. */
class PackageSalesLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-27 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
    }

    private function package(array $attributes = []): Package
    {
        $service = Service::factory()->create(['name' => 'Проживание']);
        app(ServicePricing::class)->addPrice($service, '450000', Carbon::parse('2026-01-01'));
        $package = Package::create($attributes + ['name' => 'Black Friday', 'validity_value' => 2, 'validity_unit' => 'months', 'sold_online' => true]);
        $package->items()->create(['service_id' => $service->id, 'quantity' => 1]);

        return $package;
    }

    private function draft(Package $package): void
    {
        app(CertificateManager::class)->createDraft(['package_id' => $package->id, 'customer_id' => Customer::factory()->create()->id]);
    }

    public function test_without_the_toggle_nothing_is_limited_and_values_are_cleared(): void
    {
        $package = $this->package(['sales_limits_enabled' => false, 'sales_quota' => 1, 'sales_until' => '2026-01-01']);

        $this->assertNull($package->sales_quota);
        $this->assertNull($package->sales_until);
        $this->draft($package);
        $this->draft($package);
        $this->assertSame(PackageSales::OPEN, app(PackageSales::class)->state($package));
    }

    public function test_quota_blocks_further_sales_and_zero_means_unlimited(): void
    {
        $package = $this->package(['sales_limits_enabled' => true, 'sales_quota' => 1]);
        $this->draft($package);
        $this->assertSame(PackageSales::SOLD_OUT, app(PackageSales::class)->state($package));

        try {
            $this->draft($package);
            $this->fail('Лимит должен блокировать оформление');
        } catch (DomainRuleViolation $e) {
            $this->assertStringContainsString('Лимит продаж', $e->getMessage());
        }

        $package->update(['sales_quota' => 0]);
        $this->assertNull($package->fresh()->sales_quota);
        $this->draft($package->fresh());
        $this->assertSame(PackageSales::OPEN, app(PackageSales::class)->state($package->fresh()));
    }

    public function test_director_can_raise_the_quota(): void
    {
        $package = $this->package(['sales_limits_enabled' => true, 'sales_quota' => 1]);
        $this->draft($package);
        $package->update(['sales_quota' => 2]);

        $this->draft($package->fresh());
        $this->assertSame(0, app(PackageSales::class)->remaining($package->fresh()));
    }

    public function test_sales_window_blocks_before_and_after(): void
    {
        $before = $this->package(['name' => 'Early', 'sales_limits_enabled' => true, 'sales_from' => '2026-11-28']);
        $after = $this->package(['name' => 'Late', 'sales_limits_enabled' => true, 'sales_until' => '2026-11-26']);
        $open = $this->package(['name' => 'Open', 'sales_limits_enabled' => true, 'sales_from' => '2026-11-27', 'sales_until' => '2026-11-27']);

        $this->assertSame(PackageSales::NOT_STARTED, app(PackageSales::class)->state($before));
        $this->assertSame(PackageSales::ENDED, app(PackageSales::class)->state($after));
        $this->assertSame(PackageSales::OPEN, app(PackageSales::class)->state($open));
        $this->expectException(DomainRuleViolation::class);
        $this->draft($after);
    }

    public function test_window_must_not_be_reversed(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->package(['sales_limits_enabled' => true, 'sales_from' => '2026-12-01', 'sales_until' => '2026-11-01']);
    }

    public function test_closed_package_stays_on_the_site_with_a_note_and_without_the_form(): void
    {
        $package = $this->package(['sales_limits_enabled' => true, 'sales_until' => '2026-11-26']);

        $this->get('/?lang=ru')->assertOk()->assertSee('Black Friday')->assertSee('Продажа завершена');
        $this->get('/order/'.$package->id.'?lang=ru')->assertOk()->assertSee('Продажа завершена')->assertDontSee('<form method="post"', false);
        $this->post('/order/'.$package->id, [])->assertRedirect();

        $package->update(['sales_limits_enabled' => false]);
        $this->get('/?lang=ru')->assertDontSee('Продажа завершена');
        $this->get('/order/'.$package->id.'?lang=ru')->assertSee('<form method="post"', false);
    }

    public function test_package_form_has_the_toggle_and_saves_the_limits(): void
    {
        $package = $this->package();

        Livewire::test(EditPackage::class, ['record' => $package->getKey()])
            ->assertFormFieldHidden('sales_quota')
            ->fillForm(['sales_limits_enabled' => true, 'sales_quota' => 5, 'sales_from' => '2026-11-27', 'sales_until' => '2026-11-30'])
            ->assertFormFieldVisible('sales_quota')
            ->call('save')
            ->assertHasNoFormErrors();

        $package->refresh();
        $this->assertTrue($package->sales_limits_enabled);
        $this->assertSame(5, $package->sales_quota);
        $this->assertSame('2026-11-30', $package->sales_until->toDateString());
    }
}
