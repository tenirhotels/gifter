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
        // Реквизиты внешних поставщиков — вымышленные, с корректной контрольной цифрой.
        foreach ([
            ['Служба размещения', ProviderType::Rooms, []],
            ['Ресторан отеля', ProviderType::FoodBeverage, []],
            ['SPA-центр отеля', ProviderType::OtherDepartment, []],
            ['Канатная дорога', ProviderType::External, ['legal_form' => 'too', 'legal_name' => 'Канатная дорога (демо)', 'tax_id' => '000140002217']],
            ['Трансферная компания', ProviderType::External, ['legal_form' => 'ip', 'legal_name' => 'Трансфер (демо)', 'tax_id' => '000140002217']],
        ] as [$name, $type, $requisites]) {
            $providers[$name] = Provider::firstOrCreate(['name' => $name], ['type' => $type] + $requisites);
        }

        $categories = [];
        foreach (['Проживание', 'Питание', 'SPA и отдых', 'Экскурсии', 'Трансфер'] as $position => $name) {
            $categories[$name] = ServiceCategory::firstOrCreate(['name' => $name], ['position' => $position]);
        }

        // название, единица, категория, поставщик, цена, себестоимость, [подготовка, время, подтверждение]
        $services = [
            ['Проживание', 'ночь', 'Проживание', 'Служба размещения', '150000', '60000', [false, false, false]],
            ['Завтрак', 'персона', 'Питание', 'Служба размещения', '8500', '3500', [false, false, false]],
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

        $package = Package::firstOrCreate(['name' => 'Романтический уикенд'], [
            'description' => '2 ночи для двоих', 'validity_value' => 2, 'validity_unit' => 'months', 'is_active' => true,
        ]);
        if (! $package->items()->exists()) {
            foreach ([['Проживание', 2], ['Завтрак', 4], ['Ужин', 2], ['SPA', 2]] as $position => [$name, $quantity]) {
                $package->items()->create(['service_id' => Service::where('name', $name)->value('id'), 'quantity' => $quantity, 'position' => $position]);
            }
        }

        $weekend = Package::firstOrCreate(['name' => 'Ночь в отеле'], [
            'description' => 'Проживание с завтраком', 'validity_value' => 2, 'validity_unit' => 'months', 'is_active' => true,
        ]);
        if (! $weekend->items()->exists()) {
            foreach ([['Проживание', 1], ['Завтрак', 2]] as $position => [$name, $quantity]) {
                $weekend->items()->create(['service_id' => Service::where('name', $name)->value('id'), 'quantity' => $quantity, 'position' => $position]);
            }
        }

        $blogger = Package::firstOrCreate(['name' => 'Блогер: ужин и SPA'], [
            'description' => 'Бесплатный пакет для блогеров и бартера', 'validity_value' => 1, 'validity_unit' => 'months',
            'is_complimentary' => true, 'complimentary_purpose' => 'blogger', 'is_active' => true,
        ]);
        if (! $blogger->items()->exists()) {
            foreach ([['Ужин', 2], ['SPA', 2]] as $position => [$name, $quantity]) {
                $blogger->items()->create(['service_id' => Service::where('name', $name)->value('id'), 'quantity' => $quantity, 'position' => $position]);
            }
        }
    }
}
