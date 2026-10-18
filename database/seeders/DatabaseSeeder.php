<?php

namespace Database\Seeders;

use App\Enums\ProviderType;
use App\Models\Package;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\ServicePricing;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Демо-каталог: поставщики, категории, услуги с ценами и себестоимостью, пакет.
 * Пользователей не создаёт — для этого есть php artisan gifter:create-user.
 */
class DatabaseSeeder extends Seeder
{
    public function run(ServicePricing $pricing): void
    {
        $providers = [];
        foreach ([
            ['Отель', ProviderType::Hotel],
            ['Ресторан отеля', ProviderType::Restaurant],
            ['SPA-центр отеля', ProviderType::InternalDepartment],
            ['Канатная дорога', ProviderType::ThirdParty],
            ['Трансферная компания', ProviderType::ThirdParty],
        ] as [$name, $type]) {
            $providers[$name] = Provider::firstOrCreate(['name' => $name], ['type' => $type]);
        }

        $categories = [];
        foreach (['Проживание', 'Питание', 'SPA и отдых', 'Экскурсии', 'Трансфер'] as $position => $name) {
            $categories[$name] = ServiceCategory::firstOrCreate(['name' => $name], ['position' => $position]);
        }

        // название, единица, категория, поставщик, цена, себестоимость, [подготовка, время, подтверждение]
        $services = [
            ['Проживание', 'ночь', 'Проживание', 'Отель', '150000', '60000', [false, false, false]],
            ['Завтрак', 'персона', 'Питание', 'Отель', '8500', '3500', [false, false, false]],
            ['Ужин', 'персона', 'Питание', 'Ресторан отеля', '15000', '6000', [true, true, true]],
            ['SPA', 'сеанс', 'SPA и отдых', 'SPA-центр отеля', '40000', '12000', [true, true, false]],
            ['Канатная дорога', 'билет', 'Экскурсии', 'Канатная дорога', '12000', '10000', [true, true, false]],
            ['Трансфер', 'поездка', 'Трансфер', 'Трансферная компания', '20000', '15000', [true, true, true]],
        ];

        foreach ($services as [$name, $unit, $category, $provider, $price, $cost, [$fulfillment, $scheduling, $confirmation]]) {
            $service = Service::firstOrCreate(['name' => $name], [
                'unit' => $unit,
                'category_id' => $categories[$category]->id,
                'provider_id' => $providers[$provider]->id,
                'requires_fulfillment' => $fulfillment,
                'requires_scheduling' => $scheduling,
                'requires_confirmation' => $confirmation,
            ]);
            if (! $service->prices()->exists()) {
                $pricing->addPrice($service, $price, Carbon::parse('2026-01-01'), cost: $cost);
            }
        }

        $package = Package::firstOrCreate(['name' => 'Романтический уикенд'], ['description' => '2 ночи для двоих', 'is_active' => true]);
        if (! $package->items()->exists()) {
            foreach ([['Проживание', 2], ['Завтрак', 4], ['Ужин', 2], ['SPA', 2]] as $position => [$name, $quantity]) {
                $package->items()->create(['service_id' => Service::where('name', $name)->value('id'), 'quantity' => $quantity, 'position' => $position]);
            }
        }
    }
}
