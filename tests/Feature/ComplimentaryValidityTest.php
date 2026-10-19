<?php

namespace Tests\Feature;

use App\Enums\ComplimentaryPurpose;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Certificates\Pages\CreateCertificate;
use App\Models\Certificate;
use App\Models\CertificateBatch;
use App\Models\Customer;
use App\Models\Package;
use App\Models\User;
use App\Services\CertificateBatchManager;
use App\Services\CertificateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Замечания 24.10.2026: цели бесплатных и срок, выбранный при выпуске. */
class ComplimentaryValidityTest extends TestCase
{
    use RefreshDatabase;

    private Package $free;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $this->free = Package::create(['name' => 'Инфлюенсер', 'validity_value' => 1, 'validity_unit' => 'months', 'is_complimentary' => true, 'complimentary_purpose' => 'influencers']);
    }

    private function draft(?array $validity, ?Package $package = null): Certificate
    {
        return app(CertificateManager::class)->createDraft([
            'package_id' => ($package ?? $this->free)->id,
            'complimentary_reason' => 'Обзор',
            'customer_id' => Customer::factory()->create()->id,
            'validity' => $validity,
        ]);
    }

    public function test_purposes_have_owner_wording(): void
    {
        $this->assertSame(['influencers', 'partners', 'barter', 'loyalty', 'compensation', 'internal', 'other'],
            array_map(fn ($case) => $case->value, ComplimentaryPurpose::cases()));
        $this->assertSame('Компенсация', ComplimentaryPurpose::Compensation->getLabel());
        $this->assertStringContainsString('укажите причину', ComplimentaryPurpose::Other->getDescription());
    }

    public function test_package_mode_counts_from_sale_date(): void
    {
        $certificate = $this->draft(null);

        $this->assertSame(['2026-10-18', '2026-11-18'], [$certificate->valid_from->toDateString(), $certificate->valid_until->toDateString()]);
        $this->assertFalse($certificate->custom_validity);
    }

    public function test_duration_from_chosen_date(): void
    {
        $certificate = $this->draft(['mode' => 'duration', 'from' => '2026-12-01', 'value' => 10, 'unit' => 'days']);

        $this->assertSame(['2026-12-01', '2026-12-11'], [$certificate->valid_from->toDateString(), $certificate->valid_until->toDateString()]);
        $this->assertTrue($certificate->custom_validity);
        $this->assertStringContainsString('срок задан вручную', $certificate->history()->first()->description);
    }

    public function test_until_specific_day(): void
    {
        $certificate = $this->draft(['mode' => 'until_date', 'until' => '2026-12-31']);

        $this->assertSame(['2026-10-18', '2026-12-31'], [$certificate->valid_from->toDateString(), $certificate->valid_until->toDateString()]);
    }

    public function test_validation_rules(): void
    {
        $cases = [
            ['mode' => 'duration', 'from' => '2026-10-01', 'value' => 1, 'unit' => 'months'],   // раньше выпуска
            ['mode' => 'until_date', 'until' => '2026-10-10'],                                 // в прошлом
            ['mode' => 'until_date', 'from' => '2026-12-01', 'until' => '2026-11-01'],        // конец раньше начала
            ['mode' => 'duration', 'value' => 0, 'unit' => 'days'],
            ['mode' => 'until_date'],
        ];
        foreach ($cases as $validity) {
            try {
                $this->draft($validity);
                $this->fail('Принят срок: '.json_encode($validity));
            } catch (DomainRuleViolation) {
            }
        }

        $this->expectException(DomainRuleViolation::class);
        $this->draft(['mode' => 'until_date', 'until' => '2026-12-31'], Package::find($this->emptyPackageId()));
    }

    public function test_custom_validity_survives_sale_date_change_but_package_change_resets_it(): void
    {
        $certificate = $this->draft(['mode' => 'until_date', 'from' => '2026-11-01', 'until' => '2026-12-31']);

        app(CertificateManager::class)->updateDraft($certificate, ['sold_at' => '2026-10-20', 'sold_at_reason' => 'Дата по договору']);
        $this->assertSame('2026-12-31', $certificate->refresh()->valid_until->toDateString());

        $other = Package::create(['name' => 'Партнёры', 'validity_value' => 2, 'validity_unit' => 'months', 'is_complimentary' => true, 'complimentary_purpose' => 'partners']);
        app(CertificateManager::class)->updateDraft($certificate, ['package_id' => $other->id]);
        $this->assertFalse($certificate->refresh()->custom_validity);
        $this->assertSame('2026-12-20', $certificate->valid_until->toDateString());
    }

    public function test_batch_with_custom_validity_applies_to_all(): void
    {
        $batch = app(CertificateBatchManager::class)->create($this->free, 3, [
            'complimentary_reason' => 'Фестиваль', 'complimentary_purpose' => 'partners',
            'validity' => ['mode' => 'duration', 'from' => '2026-11-15', 'value' => 2, 'unit' => 'months'],
        ]);

        $this->assertSame('2027-01-15', $batch->valid_until->toDateString());
        $this->assertSame(['2027-01-15'], $batch->certificates()->get()->map(fn ($c) => $c->valid_until->toDateString())->unique()->values()->all());
        $this->assertTrue($batch->certificates()->first()->custom_validity);

        $plain = app(CertificateBatchManager::class)->create($this->free, 2, ['complimentary_reason' => 'Розыгрыш']);
        $this->assertNull($plain->valid_from);
    }

    public function test_wizard_passes_validity_for_one_and_several(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::SalesManager]]));
        Livewire::test(CreateCertificate::class)
            ->fillForm([
                'package_id' => $this->free->id, 'complimentary_purpose' => 'compensation', 'complimentary_reason' => 'Шум в номере',
                'validity' => ['mode' => 'until_date', 'from' => '2026-10-18', 'until' => '2027-03-01'],
            ])
            ->assertSee('18.10.2026 — 01.03.2027 (задан вручную)')
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame('2027-03-01', Certificate::firstOrFail()->valid_until->toDateString());

        Livewire::test(CreateCertificate::class)
            ->fillForm([
                'package_id' => $this->free->id, 'certificate_count' => 2, 'complimentary_purpose' => 'internal', 'complimentary_reason' => 'Корпоратив',
                'validity' => ['mode' => 'duration', 'from' => '2026-12-20', 'value' => 14, 'unit' => 'days'],
            ])
            ->call('create')->assertHasNoFormErrors();
        $batch = CertificateBatch::firstOrFail();
        $this->assertSame('2027-01-03', $batch->valid_until->toDateString());
        $this->assertSame(ComplimentaryPurpose::Internal, $batch->purpose);
        $this->assertSame(2, $batch->certificates()->count());
    }

    public function test_choosing_package_prefills_purpose(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::SalesManager]]));
        Livewire::test(CreateCertificate::class)
            ->set('data.package_id', $this->free->id)
            ->assertSet('data.complimentary_purpose', 'influencers');
    }

    public function test_paid_package_shows_no_validity_choice(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::Sales]]));
        Livewire::test(CreateCertificate::class)
            ->fillForm(['package_id' => $this->emptyPackageId()])
            ->assertFormFieldHidden('validity.mode');
    }

    public function test_old_purposes_are_migrated(): void
    {
        $migration = require database_path('migrations/2026_10_24_000100_complimentary_purposes_and_custom_validity.php');
        DB::table('packages')->where('id', $this->free->id)->update(['complimentary_purpose' => 'blogger']);
        $migration->down();
        DB::table('packages')->where('id', $this->free->id)->update(['complimentary_purpose' => 'blogger']);
        $migration->up();

        $this->assertSame('influencers', DB::table('packages')->where('id', $this->free->id)->value('complimentary_purpose'));
    }
}
