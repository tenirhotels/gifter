<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\ProviderType;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Filament\Resources\Certificates\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\RelationManagers\PricesRelationManager;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Recipient;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\CertificateManager;
use App\Services\ServicePricing;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private Provider $restaurant;

    private ServiceCategory $food;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $this->restaurant = Provider::create(['name' => 'Ресторан отеля', 'type' => ProviderType::FoodBeverage]);
        $this->food = ServiceCategory::create(['name' => 'Питание']);
    }

    private function as(Role ...$roles): User
    {
        $user = User::factory()->create(['roles' => $roles]);
        $this->actingAs($user);

        return $user;
    }

    private function dinner(array $attributes = []): Service
    {
        $service = Service::factory()->create($attributes + [
            'name' => 'Ужин', 'category_id' => $this->food->id, 'provider_id' => $this->restaurant->id,
            'requires_fulfillment' => true, 'requires_scheduling' => true, 'requires_confirmation' => true,
        ]);
        app(ServicePricing::class)->addPrice($service, '15000', Carbon::parse('2026-01-01'), cost: '6000');

        return $service;
    }

    private function draft(array $data = []): Certificate
    {
        return app(CertificateManager::class)->createDraft($data + [
            'package_id' => $this->emptyPackageId(),
            'customer_id' => Customer::factory()->create()->id,
            'recipient_id' => Recipient::factory()->create()->id,
        ]);
    }

    public function test_director_creates_service_with_provider_category_and_flags(): void
    {
        $this->as(Role::SalesDirector);

        Livewire::test(CreateService::class)
            ->fillForm([
                'name' => 'Трансфер', 'unit' => 'поездка', 'category_id' => $this->food->id, 'provider_id' => $this->restaurant->id,
                'requires_fulfillment' => true, 'requires_scheduling' => true, 'requires_confirmation' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = Service::where('name', 'Трансфер')->firstOrFail();
        $this->assertTrue($service->requires_confirmation);
        $this->assertSame($this->restaurant->id, $service->provider_id);
    }

    public function test_service_requires_provider_and_category(): void
    {
        Livewire::test(CreateService::class)
            ->fillForm(['name' => 'Без поставщика', 'unit' => 'шт.'])
            ->call('create')
            ->assertHasFormErrors(['category_id' => 'required', 'provider_id' => 'required']);
    }

    public function test_scheduling_or_confirmation_imply_fulfillment(): void
    {
        $service = Service::factory()->create(['requires_confirmation' => true]);

        $this->assertTrue($service->refresh()->requires_fulfillment);
    }

    public function test_item_snapshots_cost_provider_and_flags(): void
    {
        $dinner = $this->dinner();
        $certificate = $this->draft();
        $item = app(CertificateManager::class)->addItem($certificate, $dinner, 2);

        $dinner->update(['requires_confirmation' => false, 'provider_id' => Provider::create(['name' => 'Другой', 'type' => ProviderType::External])->id]);
        app(ServicePricing::class)->addPrice($dinner, '18000', Carbon::parse('2026-11-01'), cost: '9000');

        $item->refresh();
        $this->assertSame('6000.00', (string) $item->unit_cost);
        $this->assertSame('12000.00', (string) $item->line_cost);
        $this->assertTrue($item->requires_confirmation);
        $this->assertSame($this->restaurant->id, $item->provider_id);
        $this->assertSame('12000.00', (string) $certificate->refresh()->total_cost);
        $this->assertSame('30000.00', (string) $certificate->total_amount);
    }

    public function test_unknown_cost_is_flagged(): void
    {
        $service = Service::factory()->create();
        app(ServicePricing::class)->addPrice($service, '1000', Carbon::parse('2026-01-01'));
        $certificate = $this->draft();
        app(CertificateManager::class)->addItem($certificate, $service, 1);

        $this->assertTrue($certificate->hasUnknownCost());
        $this->assertSame('0.00', (string) $certificate->refresh()->total_cost);
    }

    public function test_negative_cost_is_rejected(): void
    {
        $this->expectException(DomainRuleViolation::class);
        app(ServicePricing::class)->addPrice(Service::factory()->create(), '1000', Carbon::parse('2026-01-01'), cost: '-1');
    }

    public function test_complimentary_needs_director_approval_then_composition_is_locked(): void
    {
        $dinner = $this->dinner();
        $this->as(Role::SalesManager);
        $certificate = $this->draft(['package_id' => $this->complimentaryPackageId(), 'complimentary_reason' => 'Партнёр', 'customer_id' => null]);
        app(CertificateManager::class)->addItem($certificate, $dinner, 2);

        try {
            app(CertificateManager::class)->issue($certificate);
            $this->fail('Выпущен без согласования');
        } catch (DomainRuleViolation) {
        }
        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->assertActionHidden('approveComplimentary')
            ->assertActionHidden('issue');

        $this->as(Role::SalesDirector);
        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->assertActionHidden('issue')
            ->callAction('approveComplimentary', ['comment' => 'Согласовано']);
        $this->assertTrue($certificate->refresh()->isComplimentaryApproved());

        $this->as(Role::SalesManager);
        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->assertActionHidden(TestAction::make('addItem')->table());
        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])->callAction('issue');
        $this->assertSame(CertificateStatus::Active, $certificate->refresh()->status);

        $this->expectException(DomainRuleViolation::class);
        app(CertificateManager::class)->approveComplimentary($certificate);
    }

    public function test_approved_complimentary_composition_cannot_change(): void
    {
        $certificate = $this->draft(['package_id' => $this->complimentaryPackageId(), 'complimentary_reason' => 'Розыгрыш']);
        app(CertificateManager::class)->addItem($certificate, $this->dinner(), 1);
        app(CertificateManager::class)->approveComplimentary($certificate);

        $this->expectException(DomainRuleViolation::class);
        app(CertificateManager::class)->addItem($certificate, Service::first(), 1);
    }

    public function test_costs_are_hidden_from_sales(): void
    {
        $dinner = $this->dinner();

        $this->as(Role::Sales);
        Livewire::test(PricesRelationManager::class, ['ownerRecord' => $dinner, 'pageClass' => EditService::class])
            ->assertTableColumnHidden('cost');

        $this->as(Role::Finance);
        $certificate = $this->draft();
        app(CertificateManager::class)->addItem($certificate, $dinner, 1);
        $this->get("/admin/certificates/{$certificate->id}")->assertOk()->assertSee('Себестоимость')->assertSee('Маржа');
    }

    public function test_catalog_pages_access(): void
    {
        $this->as(Role::SalesDirector);
        foreach (['providers', 'providers/create', 'service-categories', 'service-categories/create'] as $path) {
            $this->get("/admin/{$path}")->assertOk();
        }

        $this->as(Role::Sales);
        $this->get('/admin/providers')->assertOk();
        $this->get('/admin/providers/create')->assertForbidden();
        $this->get('/admin/service-categories/create')->assertForbidden();

        $this->as(Role::FrontOffice);
        $this->get('/admin/providers')->assertForbidden();
    }
}
