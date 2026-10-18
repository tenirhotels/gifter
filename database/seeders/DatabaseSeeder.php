<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\Service;
use App\Services\ServicePricing;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Демо-каталог: услуги, цены, пакет. Пользователей не создаёт —
 * для этого есть php artisan gifter:create-user.
 */
class DatabaseSeeder extends Seeder
{
    public function run(ServicePricing $pricing): void
    {
        $services = [
            ['Проживание', 'ночь', '150000'],
            ['Завтрак', 'персона', '8500'],
            ['Ужин', 'персона', '15000'],
            ['SPA', 'сеанс', '40000'],
            ['Конная прогулка', 'сеанс', '35000'],
            ['Трансфер', 'поездка', '20000'],
        ];

        foreach ($services as [$name, $unit, $price]) {
            $service = Service::firstOrCreate(['name' => $name], ['unit' => $unit, 'is_active' => true]);
            if (! $service->prices()->exists()) {
                $pricing->addPrice($service, $price, Carbon::parse('2026-01-01'));
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
