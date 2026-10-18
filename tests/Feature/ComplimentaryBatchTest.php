<?php

namespace Tests\Feature;

use App\Enums\BatchStatus;
use App\Enums\CertificateStatus;
use App\Enums\ComplimentaryPurpose;
use App\Enums\ProviderType;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\CertificateBatches\Pages\CreateCertificateBatch;
use App\Filament\Resources\CertificateBatches\Pages\ViewCertificateBatch;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Filament\Resources\Providers\Pages\CreateProvider;
use App\Models\CertificateBatch;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Provider;
use App\Models\Recipient;
use App\Models\Service;
use App\Models\User;
use App\Services\CertificateBatchManager;
use App\Services\CertificateDocument;
use App\Services\CertificateManager;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Замечания 23.10.2026: реквизиты поставщика, бесплатные пакеты, выпуск блоками. */
class ComplimentaryBatchTest extends TestCase
{
    use RefreshDatabase;

    private Package $blogger;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $service = Service::factory()->create(['name' => 'Ужин']);
        app(ServicePricing::class)->addPrice($service, '15000', Carbon::parse('2026-01-01'), cost: '6000');
        $this->blogger = Package::create([
            'name' => 'Блогер: ужин', 'validity_value' => 1, 'validity_unit' => 'months',
            'is_complimentary' => true, 'complimentary_purpose' => 'influencers',
        ]);
        $this->blogger->items()->create(['service_id' => $service->id, 'quantity' => 2]);
    }

    private function as(Role ...$roles): User
    {
        $user = User::factory()->create(['roles' => $roles]);
        $this->actingAs($user);

        return $user;
    }

    // Внешний поставщик: ИП/ИИН или ТОО/БИН

    public function test_external_provider_requires_legal_form_and_valid_tax_id(): void
    {
        $base = ['name' => 'Канатная дорога', 'type' => 'external'];

        Livewire::test(CreateProvider::class)->fillForm($base)->call('create')
            ->assertHasFormErrors(['legal_form' => 'required', 'legal_name' => 'required', 'tax_id' => 'required']);
        Livewire::test(CreateProvider::class)->fillForm($base + ['legal_form' => 'too', 'legal_name' => 'Шымбулак', 'tax_id' => '940140000386'])
            ->call('create')->assertHasFormErrors(['tax_id']);
        Livewire::test(CreateProvider::class)->fillForm($base + ['legal_form' => 'too', 'legal_name' => 'Шымбулак', 'tax_id' => '940140000385'])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame('ТОО Шымбулак, БИН 940140000385', Provider::firstOrFail()->requisites());
    }

    public function test_hotel_department_does_not_need_requisites(): void
    {
        Livewire::test(CreateProvider::class)->fillForm(['name' => 'Ресторан', 'type' => 'food_beverage'])->call('create')->assertHasNoFormErrors();
        $this->assertNull(Provider::firstOrFail()->requisites());
    }

    public function test_provider_labels_are_russian_only(): void
    {
        $this->assertSame('Внешний поставщик', ProviderType::External->getLabel());
        $this->assertSame('Служба размещения', ProviderType::Rooms->getLabel());
    }

    // Бесплатный пакет

    public function test_complimentary_package_gives_complimentary_certificate_with_purpose(): void
    {
        $certificate = app(CertificateManager::class)->createDraft(['package_id' => $this->blogger->id, 'complimentary_reason' => 'Обзор']);

        $this->assertTrue($certificate->isComplimentary());
        $this->assertSame(ComplimentaryPurpose::Influencers, $certificate->complimentary_purpose);
        $this->assertSame('12000.00', (string) $certificate->total_cost);
    }

    public function test_switching_to_regular_package_turns_draft_into_sale(): void
    {
        $certificate = app(CertificateManager::class)->createDraft(['package_id' => $this->blogger->id, 'complimentary_reason' => 'Обзор', 'customer_id' => Customer::factory()->create()->id]);
        app(CertificateManager::class)->updateDraft($certificate, ['package_id' => $this->emptyPackageId()]);

        $this->assertFalse($certificate->refresh()->isComplimentary());
        $this->assertNull($certificate->complimentary_purpose);
    }

    // Блоки

    public function test_batch_flow_create_approve_issue_and_pdf(): void
    {
        $this->as(Role::SalesManager);
        Livewire::test(CreateCertificateBatch::class)
            ->fillForm(['package_id' => $this->blogger->id, 'quantity' => 5, 'purpose' => 'influencers', 'reason' => 'Пресс-тур, октябрь'])
            ->call('create')->assertHasNoFormErrors();
        $batch = CertificateBatch::firstOrFail();
        $this->assertSame(5, $batch->certificates()->count());
        $this->assertSame(['GIF-000001', 'GIF-000005'], [$batch->certificates()->first()->number, $batch->certificates()->get()->last()->number]);

        Livewire::test(ViewCertificateBatch::class, ['record' => $batch->id])
            ->assertActionHidden('approveBatch')
            ->assertActionHidden('issueBatch');

        $this->as(Role::SalesDirector);
        Livewire::test(ViewCertificateBatch::class, ['record' => $batch->id])->callAction('approveBatch', ['comment' => 'Ок']);
        $this->assertSame(BatchStatus::Approved, $batch->refresh()->status);

        $this->as(Role::SalesManager);
        Livewire::test(ViewCertificateBatch::class, ['record' => $batch->id])->callAction('issueBatch');
        $this->assertSame(BatchStatus::Issued, $batch->refresh()->status);
        $this->assertSame(5, $batch->certificates()->where('status', CertificateStatus::Active)->count());

        $pdf = app(CertificateDocument::class)->batchPdf($batch);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(5, substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages'));
    }

    public function test_batch_certificate_actions_are_batch_level_but_recipient_is_individual(): void
    {
        $batch = app(CertificateBatchManager::class)->create($this->blogger, 2, 'Бартер');
        $certificate = $batch->certificates()->first();

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->assertActionHidden('approveComplimentary')->assertActionHidden('issue')->assertActionHidden('cancel')
            ->assertActionVisible('openBatch');
        try {
            app(CertificateManager::class)->issue($certificate);
            $this->fail('Выпуск одного сертификата блока');
        } catch (DomainRuleViolation) {
        }

        app(CertificateBatchManager::class)->approve($batch);
        app(CertificateBatchManager::class)->issue($batch);
        app(CertificateManager::class)->setRecipient($certificate->refresh(), Recipient::factory()->create()->id);
        $this->assertNotNull($certificate->refresh()->recipient_id);
    }

    public function test_batch_rules(): void
    {
        foreach ([[$this->blogger, 0], [$this->blogger, 201], [Package::create(['name' => 'Продажа']), 3]] as [$package, $quantity]) {
            try {
                app(CertificateBatchManager::class)->create($package, $quantity, 'тест');
                $this->fail("Блок {$package->name} × {$quantity}");
            } catch (DomainRuleViolation) {
            }
        }

        $batch = app(CertificateBatchManager::class)->create($this->blogger, 3, 'Розыгрыш');
        app(CertificateBatchManager::class)->cancel($batch, 'Отменили акцию');
        $this->assertSame(BatchStatus::Cancelled, $batch->refresh()->status);
        $this->assertSame(3, $batch->certificates()->where('status', CertificateStatus::Cancelled)->count());
    }

    public function test_batch_pages_access(): void
    {
        $this->as(Role::Sales);
        $this->get('/admin/certificate-batches')->assertForbidden();

        foreach ([Role::SalesManager, Role::SalesDirector, Role::Finance] as $role) {
            $this->as($role);
            $this->get('/admin/certificate-batches')->assertOk();
        }
        $this->as(Role::SalesDirector);
        $this->get('/admin/certificate-batches/create')->assertForbidden();
    }
}
