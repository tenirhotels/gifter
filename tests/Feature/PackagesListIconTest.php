<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Models\Package;
use App\Models\Service;
use App\Models\User;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Одна колонка «На сайте» вместо «Онлайн» и «На сайте»: три состояния, причины — в подсказке. */
class PackagesListIconTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_column_shows_three_states(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $priced = Service::factory()->create();
        app(ServicePricing::class)->addPrice($priced, '1000', now()->subDay());
        $unpriced = Service::factory()->create();
        $base = ['validity_value' => 2, 'validity_unit' => 'months'];
        $shown = Package::create($base + ['name' => 'Shown', 'sold_online' => true]);
        $shown->items()->create(['service_id' => $priced->id, 'quantity' => 1]);
        $hidden = Package::create($base + ['name' => 'Hidden', 'sold_online' => true]);
        $hidden->items()->create(['service_id' => $unpriced->id, 'quantity' => 1]);
        $offline = Package::create($base + ['name' => 'Offline', 'sold_online' => false]);

        Livewire::test(ListPackages::class)
            ->assertTableColumnDoesNotExist('sold_online')
            ->assertTableColumnStateSet('shop', 'shown', $shown)
            ->assertTableColumnStateSet('shop', 'hidden', $hidden)
            ->assertTableColumnStateSet('shop', 'offline', $offline);
    }
}
