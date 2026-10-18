<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Filament\Resources\Certificates\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Certificates\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\RelationManagers\PricesRelationManager;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Recipient;
use App\Models\Service;
use App\Models\User;
use App\Services\CertificateManager;
use App\Services\PaymentManager;
use App\Services\ServicePricing;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $this->service = Service::factory()->create();
        app(ServicePricing::class)->addPrice($this->service, '10000', Carbon::parse('2026-01-01'));
    }

    private function as(Role ...$roles): User
    {
        $user = User::factory()->create(['roles' => $roles]);
        $this->actingAs($user);

        return $user;
    }

    private function draft(): Certificate
    {
        $certificate = app(CertificateManager::class)->createDraft([
            'customer_id' => Customer::factory()->create()->id,
            'recipient_id' => Recipient::factory()->create()->id,
        ]);
        app(CertificateManager::class)->addItem($certificate, $this->service, 1);

        return $certificate->refresh();
    }

    private function issued(CertificateStatus $status = CertificateStatus::Active): Certificate
    {
        $certificate = $this->draft();
        app(PaymentManager::class)->markPaid(app(PaymentManager::class)->invoice($certificate, PaymentMethod::KaspiInvoice));
        app(CertificateManager::class)->issue($certificate);
        foreach ([CertificateStatus::Reserved, CertificateStatus::InUse] as $next) {
            if ($certificate->status === $status) {
                break;
            }
            app(CertificateManager::class)->transition($certificate, $next);
        }

        return $certificate->refresh();
    }

    public static function pageAccess(): array
    {
        // роль => [страницы с доступом, страницы без доступа]
        return [
            'sales' => [Role::Sales, ['certificates', 'certificates/create', 'customers', 'customers/create', 'recipients', 'services', 'packages'], ['services/create', 'packages/create', 'users', 'audit-logs']],
            'sales manager' => [Role::SalesManager, ['certificates', 'certificates/create', 'customers', 'services'], ['services/create', 'users', 'audit-logs']],
            'sales director' => [Role::SalesDirector, ['services', 'services/create', 'packages/create', 'certificates'], ['certificates/create', 'customers/create', 'users', 'audit-logs']],
            'front office' => [Role::FrontOffice, ['certificates', 'customers'], ['certificates/create', 'customers/create', 'services', 'packages', 'users', 'audit-logs']],
            'finance' => [Role::Finance, ['certificates', 'customers'], ['certificates/create', 'services', 'packages', 'users', 'audit-logs']],
            'super admin' => [Role::SuperAdmin, ['certificates/create', 'services/create', 'users', 'users/create', 'audit-logs'], []],
        ];
    }

    #[DataProvider('pageAccess')]
    public function test_page_access_by_role(Role $role, array $allowed, array $denied): void
    {
        $this->as($role);

        foreach ($allowed as $path) {
            $this->get("/admin/{$path}")->assertOk();
        }
        foreach ($denied as $path) {
            $this->get("/admin/{$path}")->assertForbidden();
        }
    }

    public function test_user_without_roles_cannot_enter_panel(): void
    {
        $this->as();
        $this->get('/admin')->assertForbidden();
    }

    public function test_sales_sells_but_cannot_cancel_or_change_validity(): void
    {
        $certificate = $this->draft();
        $this->as(Role::Sales);

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->assertActionVisible(TestAction::make('addItem')->table());
        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->callAction(TestAction::make('invoice')->table(), ['method' => 'kaspi_invoice', 'amount' => '10000']);
        app(PaymentManager::class)->markPaid($certificate->payments()->first());

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->assertActionHidden('cancel')
            ->callAction('issue');
        $this->assertSame(CertificateStatus::Active, $certificate->refresh()->status);

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->assertActionHidden('changeValidity')
            ->assertActionHidden('cancel')
            ->assertActionHidden('reserve');
    }

    public function test_sales_manager_can_change_validity_and_cancel(): void
    {
        $certificate = $this->issued();
        $this->as(Role::SalesManager);

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->callAction('changeValidity', ['valid_from' => '2026-10-18', 'valid_until' => '2027-12-31', 'reason' => 'Продление'])
            ->assertHasNoActionErrors();
        $this->assertSame('2027-12-31', $certificate->refresh()->valid_until->toDateString());

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->callAction('cancel', ['reason' => 'Возврат']);
        $this->assertSame(CertificateStatus::Cancelled, $certificate->refresh()->status);
    }

    public function test_front_office_reserves_checks_in_and_redeems_but_cannot_sell(): void
    {
        $certificate = $this->issued();
        $this->as(Role::FrontOffice);

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])->callAction('reserve');
        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])->callAction('checkIn');
        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->assertActionHidden('cancel')
            ->assertActionHidden('changeValidity')
            ->callAction('redeem');
        $this->assertSame(CertificateStatus::Used, $certificate->refresh()->status);

        $draft = $this->draft();
        $this->as(Role::FrontOffice);
        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $draft, 'pageClass' => ViewCertificate::class])
            ->assertActionHidden(TestAction::make('addItem')->table());
        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $draft, 'pageClass' => ViewCertificate::class])
            ->assertActionHidden(TestAction::make('invoice')->table());
        Livewire::test(ViewCertificate::class, ['record' => $draft->id])->assertActionHidden('issue');
    }

    public function test_sales_cannot_mark_reservation(): void
    {
        $certificate = $this->issued();
        $this->as(Role::Sales, Role::Finance);

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])->assertActionHidden('reserve');
    }

    public function test_finance_is_read_only_for_now(): void
    {
        $draft = $this->draft();
        $this->as(Role::Finance);

        $this->get("/admin/certificates/{$draft->id}")->assertOk();
        $this->get("/admin/certificates/{$draft->id}/edit")->assertForbidden();
        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $draft, 'pageClass' => ViewCertificate::class])
            ->assertActionHidden(TestAction::make('invoice')->table());
    }

    public function test_only_director_manages_prices(): void
    {
        $this->as(Role::SalesManager);
        Livewire::test(PricesRelationManager::class, ['ownerRecord' => $this->service, 'pageClass' => EditService::class])
            ->assertActionHidden(TestAction::make('addPrice')->table());

        $this->as(Role::SalesDirector);
        Livewire::test(PricesRelationManager::class, ['ownerRecord' => $this->service, 'pageClass' => EditService::class])
            ->callAction(TestAction::make('addPrice')->table(), ['price' => '12000', 'valid_from' => '2026-11-01']);
        $this->assertSame(2, $this->service->prices()->count());
    }

    public function test_roles_combine(): void
    {
        $user = $this->as(Role::Sales, Role::FrontOffice);

        $this->assertTrue($user->can(Permission::IssueCertificates));
        $this->assertTrue($user->can(Permission::RedeemCertificates));
        $this->assertFalse($user->can(Permission::ManagePrices));
    }

    public function test_inactive_user_loses_all_permissions(): void
    {
        $user = User::factory()->create(['roles' => [Role::SuperAdmin], 'is_active' => false]);

        $this->assertFalse($user->can(Permission::ViewCertificates));
    }

    public function test_last_active_admin_cannot_be_demoted(): void
    {
        $admin = User::query()->first();

        $this->expectException(DomainRuleViolation::class);
        $admin->update(['roles' => [Role::Sales]]);
    }

    public function test_admin_can_be_demoted_when_another_exists(): void
    {
        $admin = User::query()->first();
        User::factory()->create(['roles' => [Role::SuperAdmin]]);

        $admin->update(['is_active' => false]);
        $this->assertFalse($admin->refresh()->is_active);
    }

    public function test_nobody_can_delete_certificates_via_policy(): void
    {
        $certificate = $this->draft();

        $this->assertFalse(User::query()->first()->can('delete', $certificate));
    }
}
