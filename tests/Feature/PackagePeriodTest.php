<?php

namespace Tests\Feature;

use App\Enums\PackageValidityType;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Packages\Pages\CreatePackage;
use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Service;
use App\Models\User;
use App\Services\CertificateManager;
use App\Services\ServicePricing;
use App\Services\ShopAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Пакет задаёт срок двумя способами: на срок с даты продажи или на конкретный период (зимние каникулы). */
class PackagePeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
    }

    private function package(array $attributes = []): Package
    {
        $service = Service::factory()->create(['name' => 'Проживание']);
        app(ServicePricing::class)->addPrice($service, '450000', Carbon::parse('2026-01-01'));
        $package = Package::create($attributes + ['name' => 'Winter holidays', 'validity_value' => 2, 'validity_unit' => 'months', 'sold_online' => true]);
        $package->items()->create(['service_id' => $service->id, 'quantity' => 1]);

        return $package;
    }

    private function period(array $attributes = []): Package
    {
        return $this->package($attributes + ['validity_type' => 'period', 'period_from' => '2026-12-01', 'period_until' => '2027-02-01']);
    }

    public function test_certificate_gets_the_fixed_period_even_when_sold_earlier(): void
    {
        $certificate = app(CertificateManager::class)->createDraft(['package_id' => $this->period()->id, 'customer_id' => Customer::factory()->create()->id]);

        $this->assertSame('2026-12-01', $certificate->valid_from->toDateString());
        $this->assertSame('2027-02-01', $certificate->valid_until->toDateString());
        $this->assertFalse((bool) $certificate->custom_validity);
    }

    public function test_term_package_still_counts_from_sale_date(): void
    {
        $certificate = app(CertificateManager::class)->createDraft(['package_id' => $this->package()->id, 'customer_id' => Customer::factory()->create()->id]);

        $this->assertSame('2026-11-10', $certificate->valid_from->toDateString());
        $this->assertSame('2027-01-10', $certificate->valid_until->toDateString());
    }

    public function test_ended_period_cannot_be_sold_and_leaves_the_shop(): void
    {
        $package = $this->period(['period_from' => '2026-09-01', 'period_until' => '2026-11-09']);

        $this->expectException(DomainRuleViolation::class);
        try {
            app(CertificateManager::class)->createDraft(['package_id' => $package->id, 'customer_id' => Customer::factory()->create()->id]);
        } finally {
            $this->get('/')->assertDontSee('Winter holidays');
            $this->get('/order/'.$package->id)->assertNotFound();
        }
    }

    public function test_shop_shows_the_period_under_the_name_in_visitor_language(): void
    {
        $package = $this->period();
        $term = $this->package(['name' => 'Stay for two']);

        $this->get('/?lang=ru')->assertOk()->assertSee('Действует с 01.12.2026 по 01.02.2027')->assertSee('Действует 2 месяца с даты продажи');
        $this->get('/?lang=en')->assertSee('from 01.12.2026 to 01.02.2027')->assertDontSee('Действует');
        $this->get('/order/'.$package->id.'?lang=ru')->assertOk()->assertSee('Действует с 01.12.2026 по 01.02.2027');
        $this->get('/order/'.$term->id.'?lang=en')->assertOk();
        $this->assertSame('from 01.12.2026 to 01.02.2027', $package->validityText('en'));
    }

    public function test_period_must_be_complete_and_term_clears_dates(): void
    {
        $this->expectException(DomainRuleViolation::class);
        try {
            $this->assertNull(Package::create(['name' => 'Term', 'validity_value' => 1, 'validity_unit' => 'months', 'period_from' => '2026-12-01', 'period_until' => '2027-01-01'])->period_from);
            Package::create(['name' => 'Bad', 'validity_type' => PackageValidityType::Period, 'period_from' => '2027-02-01', 'period_until' => '2027-01-01']);
        } finally {
            $this->assertSame(1, Package::count());
        }
    }

    public function test_package_form_switches_between_fixed_term_and_fixed_period_with_buttons(): void
    {
        $service = Service::factory()->create();
        $form = Livewire::test(CreatePackage::class);

        $form->assertFormFieldExists('validity_type')->assertFormFieldVisible('validity_value')->assertFormFieldHidden('period_from')
            ->fillForm(['validity_type' => 'period'])
            ->assertFormFieldVisible('period_from')->assertFormFieldVisible('period_until')->assertFormFieldHidden('validity_value')
            ->fillForm(['name' => 'Зимние каникулы', 'period_from' => '2026-12-01', 'period_until' => '2027-02-01', 'items' => [['service_id' => $service->id, 'quantity' => 1]]])
            ->call('create')->assertHasNoFormErrors();

        $package = Package::where('name', 'Зимние каникулы')->firstOrFail();
        $this->assertSame(PackageValidityType::Period, $package->validity_type);
        $this->assertSame('2026-12-01', $package->period_from->toDateString());

        Livewire::test(EditPackage::class, ['record' => $package->id])
            ->assertFormFieldVisible('period_from')->assertFormFieldHidden('validity_value')
            ->fillForm(['validity_type' => 'term', 'validity_value' => 3, 'validity_unit' => 'months'])->call('save')->assertHasNoFormErrors();
        $this->assertNull($package->refresh()->period_from);
        $this->assertSame(3, $package->validity_value);
    }

    public function test_package_card_explains_why_it_is_not_on_the_site(): void
    {
        $availability = app(ShopAvailability::class);
        $package = $this->period();
        $this->assertSame([], $availability->problems($package), 'Период в будущем не мешает показу');

        $package->update(['sold_online' => false, 'is_active' => false]);
        $this->assertEqualsCanonicalizing(['Пакет не активен', 'Не включено «Продаётся онлайн»'], $availability->problems($package->refresh()));

        $package->update(['sold_online' => true, 'is_active' => true]);
        $service = $package->items()->firstOrFail()->service;
        $service->prices()->delete();
        $this->assertStringContainsString('нет действующей цены', $availability->problems($package->refresh())[0]);

        app(ServicePricing::class)->addPrice($service, '450000', Carbon::parse('2026-12-01'));
        $this->assertStringContainsString('начинает действовать только с 01.12.2026', $availability->problems($package->refresh())[0]);

        $this->get('/')->assertDontSee('Winter holidays');
    }
}
