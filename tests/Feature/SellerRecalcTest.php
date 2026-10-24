<?php

namespace Tests\Feature;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\CommissionStatus;
use App\Enums\CommissionType;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Certificates\Pages\ListCertificates;
use App\Models\Certificate;
use App\Models\CertificateCommission;
use App\Models\Package;
use App\Models\User;
use App\Services\CommissionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Продавец и комиссия у выпущенных сертификатов, перенесённых из таблицы: размер задаётся в действии. */
class SellerRecalcTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->seller = User::factory()->create(['roles' => [Role::Sales], 'name' => 'Айгерим']);
        $this->admin = User::factory()->create(['roles' => [Role::SuperAdmin]]);
        $this->actingAs($this->admin);
    }

    private function imported(CertificateStatus $status, array $attributes = []): Certificate
    {
        $package = Package::firstOrCreate(['name' => 'Архив: 1 ночь'], ['validity_value' => 60, 'validity_unit' => 'days', 'is_active' => false]);
        $n = Certificate::count() + 1;

        return Certificate::create($attributes + [
            'number' => "TST-{$n}", 'verification_token' => str()->random(32), 'package_id' => $package->id, 'status' => $status, 'kind' => CertificateKind::Sale,
            'sold_at' => '2024-03-01', 'valid_from' => '2024-03-01', 'valid_until' => '2024-06-01', 'custom_validity' => true,
            'total_amount' => '200000', 'payable_amount' => '200000', 'currency' => 'KZT', 'issued_at' => '2024-03-01', 'created_by' => $this->admin->id,
        ]);
    }

    private function assign(Certificate $certificate, string $value = '5', CommissionType $type = CommissionType::Percent): ?CertificateCommission
    {
        return app(CommissionManager::class)->assignSellerAndRecalculate($certificate, $this->seller, $type, $value, 'перенос из старой таблицы');
    }

    public function test_used_certificate_gets_seller_and_earned_commission(): void
    {
        $certificate = $this->imported(CertificateStatus::Used);

        $commission = $this->assign($certificate);

        $this->assertSame($this->seller->id, $certificate->fresh()->sold_by);
        $this->assertSame(CommissionStatus::Earned, $commission->status);
        $this->assertSame('10000.00', (string) $commission->amount);
        $this->assertNull($commission->rule_id);
        $this->assertNotNull($commission->earned_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'commission_created']);
        $this->assertStringContainsString('Продавец: Айгерим', (string) $certificate->history()->latest('id')->first()->description);
    }

    public function test_status_follows_the_certificate(): void
    {
        $this->assertSame(CommissionStatus::PendingRedemption, $this->assign($this->imported(CertificateStatus::Active))->status);
        $this->assertSame(CommissionStatus::Void, $this->assign($this->imported(CertificateStatus::Expired))->status);
        $this->assertSame(CommissionStatus::Void, $this->assign($this->imported(CertificateStatus::Cancelled))->status);
    }

    public function test_reassigning_recalculates_and_keeps_status(): void
    {
        $certificate = $this->imported(CertificateStatus::Used);
        $this->assign($certificate, '5');
        $other = User::factory()->create(['roles' => [Role::Sales], 'name' => 'Данияр']);

        $commission = app(CommissionManager::class)->assignSellerAndRecalculate($certificate->fresh(), $other, CommissionType::Fixed, '15000', 'уточнили продавца');

        $this->assertSame($other->id, $commission->seller_id);
        $this->assertSame('15000.00', (string) $commission->amount);
        $this->assertSame(CommissionStatus::Earned, $commission->status);
        $this->assertSame(1, CertificateCommission::count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'commission_recalculated']);
    }

    public function test_approved_and_paid_commissions_are_not_touched(): void
    {
        $certificate = $this->imported(CertificateStatus::Used);
        $commission = $this->assign($certificate);
        $commission->update(['status' => CommissionStatus::Approved]);

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Комиссия уже в статусе');
        $this->assign($certificate->fresh(), '7');
    }

    public function test_draft_complimentary_bad_values_and_short_reason_are_refused(): void
    {
        $manager = app(CommissionManager::class);
        $checks = [
            fn () => $this->assign($this->imported(CertificateStatus::Draft)),
            fn () => $this->assign($this->imported(CertificateStatus::Used, ['kind' => CertificateKind::Complimentary])),
            fn () => $this->assign($this->imported(CertificateStatus::Used), '0'),
            fn () => $this->assign($this->imported(CertificateStatus::Used), '150'),
            fn () => $manager->assignSellerAndRecalculate($this->imported(CertificateStatus::Used), $this->seller, CommissionType::Percent, '5', 'да'),
            fn () => $manager->assignSellerAndRecalculate($this->imported(CertificateStatus::Used), User::factory()->create(['roles' => [Role::Finance]]), CommissionType::Percent, '5', 'причина есть'),
        ];
        foreach ($checks as $i => $check) {
            try {
                $check();
                $this->fail("Проверка {$i} должна быть отклонена");
            } catch (DomainRuleViolation) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, CertificateCommission::count());
    }

    public function test_only_admin_or_sales_director_can_do_it(): void
    {
        $this->actingAs($this->seller);

        $this->expectException(DomainRuleViolation::class);
        $this->assign($this->imported(CertificateStatus::Used));
    }

    public function test_bulk_action_reports_done_and_skipped(): void
    {
        $used = $this->imported(CertificateStatus::Used);
        $draft = $this->imported(CertificateStatus::Draft);

        Livewire::test(ListCertificates::class)
            ->callTableBulkAction('sellerCommission', [$used, $draft], ['seller' => $this->seller->id, 'type' => 'percent', 'value' => '5', 'reason' => 'перенос из таблицы'])
            ->assertNotified();

        $this->assertSame($this->seller->id, $used->fresh()->sold_by);
        $this->assertNull($draft->fresh()->sold_by);
        $this->assertSame(1, CertificateCommission::count());
    }

    public function test_filters_find_imported_certificates_without_seller(): void
    {
        $imported = $this->imported(CertificateStatus::Used, ['legacy_number' => '2021-0001']);
        $own = $this->imported(CertificateStatus::Used, ['sold_by' => $this->seller->id]);

        Livewire::test(ListCertificates::class)
            ->filterTable('imported')->assertCanSeeTableRecords([$imported])->assertCanNotSeeTableRecords([$own])
            ->removeTableFilters()
            ->filterTable('no_seller')->assertCanSeeTableRecords([$imported])->assertCanNotSeeTableRecords([$own]);
    }
}
