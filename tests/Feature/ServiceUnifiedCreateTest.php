<?php

namespace Tests\Feature;

use App\Enums\ProviderType;
use App\Enums\Role;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\PmsService;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Одна форма создания услуги: из списка PMS (по ID) или вручную; цена здесь же; тумблер — только с утверждённой ценой. */
class ServiceUnifiedCreateTest extends TestCase
{
    use RefreshDatabase;

    private function pms(string $id = '31', string $name = 'Канатная дорога'): PmsService
    {
        return PmsService::create(['external_id' => $id, 'name' => $name, 'kind' => 'Common', 'status' => 'Active', 'synced_at' => now()]);
    }

    private function base(): array
    {
        return ['category_id' => ServiceCategory::create(['name' => 'Экскурсии '.uniqid()])->id, 'provider_id' => Provider::create(['name' => 'Поставщик '.uniqid(), 'type' => ProviderType::External, 'legal_form' => 'too', 'legal_name' => 'ТОО Тест', 'tax_id' => '123456789012'])->id, 'unit' => 'билет'];
    }

    public function test_admin_creates_service_from_pms_with_price_and_it_becomes_available(): void
    {
        config(['gifter.require_price_approval' => true]);
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $pms = $this->pms();

        Livewire::test(CreateService::class)
            ->fillForm(['source' => 'pms', 'pms_service_id' => '31'] + $this->base())
            ->assertFormSet(['name' => 'Канатная дорога'])
            ->fillForm(['price' => '8000', 'cost' => '4000', 'valid_from' => today()->toDateString()])
            ->call('create')->assertHasNoFormErrors();

        $service = Service::where('name', 'Канатная дорога')->sole();
        $this->assertSame($service->id, $pms->refresh()->service_id);
        $this->assertTrue($pms->allowed_in_certificate, 'Цена утверждена сразу — услуга доступна');
        $this->assertTrue($service->prices()->sole()->isApproved());
    }

    public function test_director_price_is_pending_so_the_service_is_not_yet_allowed(): void
    {
        config(['gifter.require_price_approval' => true]);
        $this->actingAs(User::factory()->create(['roles' => [Role::SalesDirector]]));
        $pms = $this->pms();

        Livewire::test(CreateService::class)
            ->fillForm(['source' => 'pms', 'pms_service_id' => '31', 'price' => '8000', 'valid_from' => today()->toDateString()] + $this->base())
            ->call('create')->assertHasNoFormErrors();

        $this->assertFalse($pms->refresh()->allowed_in_certificate);
        $this->assertFalse($pms->service->prices()->sole()->isApproved());
    }

    public function test_pms_source_requires_a_price_and_manual_does_not(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $this->pms();

        Livewire::test(CreateService::class)
            ->fillForm(['source' => 'pms', 'pms_service_id' => '31'] + $this->base())
            ->call('create')->assertHasFormErrors(['price']);

        Livewire::test(CreateService::class)
            ->fillForm(['source' => 'manual', 'name' => 'Трансфер', 'unit' => 'поездка'] + $this->base())
            ->call('create')->assertHasNoFormErrors();
        $this->assertNull(Service::where('name', 'Трансфер')->sole()->pmsService);
    }

    public function test_pms_service_cannot_be_used_twice(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $pms = $this->pms();
        $pms->update(['service_id' => Service::factory()->create()->id]);

        Livewire::test(CreateService::class)
            ->fillForm(['source' => 'pms', 'pms_service_id' => '31', 'price' => '100', 'valid_from' => today()->toDateString()] + $this->base())
            ->call('create');

        $this->assertSame(1, Service::count(), 'Дубль не создан');
    }

    public function test_one_toggle_works_for_pms_and_manual_services(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::SalesDirector]]));
        $pms = $this->pms();
        $service = Service::factory()->create(['name' => 'Канатная дорога', 'is_active' => false] + $this->base());
        $pms->update(['service_id' => $service->id]);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertSee('ID в PMS')->assertFormFieldExists('is_active')->assertFormFieldDoesNotExist('pms_allowed')
            ->fillForm(['is_active' => true])->call('save')->assertHasNoFormErrors();
        $this->assertTrue($pms->refresh()->allowed_in_certificate);
        $this->assertTrue($service->refresh()->is_active);

        Livewire::test(EditService::class, ['record' => $service->id])->fillForm(['is_active' => false])->call('save');
        $this->assertFalse($pms->refresh()->allowed_in_certificate);
        $this->assertFalse($service->refresh()->is_active);

        $manual = Service::factory()->create(['name' => 'Спа', 'is_active' => false] + $this->base());
        Livewire::test(EditService::class, ['record' => $manual->id])->assertFormFieldExists('is_active')
            ->fillForm(['is_active' => true])->call('save')->assertHasNoFormErrors();
        $this->assertTrue($manual->refresh()->is_active);
    }

    public function test_service_missing_in_pms_cannot_be_enabled(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::SalesDirector]]));
        $pms = $this->pms();
        $service = Service::factory()->create(['name' => 'Канатная дорога', 'is_active' => false] + $this->base());
        $pms->update(['service_id' => $service->id, 'missing_since' => now()]);

        Livewire::test(EditService::class, ['record' => $service->id])->fillForm(['is_active' => true])->call('save');

        $this->assertFalse($service->refresh()->is_active);
        $this->assertFalse($pms->refresh()->allowed_in_certificate);
    }
}
