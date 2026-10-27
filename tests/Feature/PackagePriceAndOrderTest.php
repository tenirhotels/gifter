<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\ShopSettings;
use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Http\Controllers\PublicShopController;
use App\Models\Package;
use App\Models\Service;
use App\Models\User;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** Цена пакета в списке (или причина её отсутствия) и порядок пакетов на сайте. */
class PackagePriceAndOrderTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = ['validity_value' => 2, 'validity_unit' => 'months'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-27 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
    }

    private function package(string $name, ?Service $service, int $qty = 1, array $extra = []): Package
    {
        $package = Package::create(self::BASE + ['name' => $name, 'sold_online' => true] + $extra);
        if ($service) {
            $package->items()->create(['service_id' => $service->id, 'quantity' => $qty]);
        }

        return $package;
    }

    private function priced(string $price = '1000'): Service
    {
        $service = Service::factory()->create();
        app(ServicePricing::class)->addPrice($service, $price, now()->subDay());

        return $service;
    }

    public function test_list_shows_price_or_reason(): void
    {
        $ok = $this->package('Ok', $this->priced('1000'), 2);
        $bare = Service::factory()->create(['name' => 'Завтрак']);
        $noPrice = $this->package('NoPrice', $bare);
        $empty = $this->package('Empty', null);
        $free = $this->package('Free', null, 1, ['is_complimentary' => true, 'complimentary_purpose' => 'barter', 'sold_online' => false]);

        Livewire::test(ListPackages::class)
            ->assertTableColumnStateSet('price', '2 000 ₸', $ok)
            ->assertTableColumnStateSet('price', 'У услуги «Завтрак» нет действующей цены на сегодня', $noPrice)
            ->assertTableColumnStateSet('price', 'В пакете нет состава (услуг)', $empty)
            ->assertTableColumnStateSet('price', 'Бесплатный', $free);
    }

    public function test_future_period_package_is_priced_on_period_start(): void
    {
        $service = Service::factory()->create();
        app(ServicePricing::class)->addPrice($service, '5000', Carbon::parse('2026-12-01'));
        $package = $this->package('Winter', $service, 1, ['validity_type' => 'period', 'period_from' => '2026-12-01', 'period_until' => '2027-02-15']);

        Livewire::test(ListPackages::class)->assertTableColumnStateSet('price', '5 000 ₸', $package);
    }

    public function test_shop_follows_position_then_name_and_settings_saves_order(): void
    {
        $service = $this->priced();
        $a = $this->package('Aaa', $service);
        $b = $this->package('Bbb', $service);
        $c = $this->package('Ccc', $service);
        $hidden = $this->package('Hidden', Service::factory()->create());

        $names = fn () => app(PublicShopController::class)->offers()->pluck('package.name')->all();
        $this->assertSame(['Aaa', 'Bbb', 'Ccc'], $names());

        $page = Livewire::test(ShopSettings::class);
        $page->assertFormSet(fn (array $state) => collect($state['shop_order'])->pluck('id')->all() === [$a->id, $b->id, $c->id]);
        $page->fillForm(['shop_order' => [['id' => $c->id, 'label' => ''], ['id' => $a->id, 'label' => ''], ['id' => $hidden->id, 'label' => ''], ['id' => $b->id, 'label' => '']]])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame([1, 2, 3], [$c->fresh()->shop_position, $a->fresh()->shop_position, $b->fresh()->shop_position]);
        $this->assertNull($hidden->fresh()->shop_position, 'Недоступный пакет порядок не получает');
        Cache::flush();
        $this->assertSame(['Ccc', 'Aaa', 'Bbb'], $names());
    }

    public function test_settings_page_is_closed_without_permission(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::Sales]]));
        $this->assertFalse(ShopSettings::canAccess());
    }
}
