<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Filament\Resources\Certificates\Pages\CreateCertificate;
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
use App\Services\ServicePricing;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
    }

    public function test_inactive_user_cannot_open_panel(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => false, 'roles' => [Role::SuperAdmin]]));
        $this->get('/admin')->assertForbidden();
    }

    public function test_all_index_pages_render(): void
    {
        foreach (['certificates', 'certificates/create', 'customers', 'recipients', 'services', 'packages', 'users', 'audit-logs'] as $path) {
            $this->get("/admin/{$path}")->assertOk();
        }
    }

    public function test_sale_through_panel_from_draft_to_issued(): void
    {
        $service = Service::factory()->create(['name' => 'SPA']);
        app(ServicePricing::class)->addPrice($service, '40000', Carbon::parse('2026-01-01'));
        $customer = Customer::factory()->create();
        $recipient = Recipient::factory()->create();

        Livewire::test(CreateCertificate::class)
            ->fillForm(['package_id' => $this->emptyPackageId(), 'customer_id' => $customer->id, 'recipient_id' => $recipient->id, 'create_invoice' => false])
            ->call('create')
            ->assertHasNoFormErrors();

        $certificate = Certificate::firstOrFail();
        $this->assertSame(CertificateStatus::Draft, $certificate->status);

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->callAction(TestAction::make('addItem')->table(), ['service_id' => $service->id, 'quantity' => 2])
            ->assertHasNoActionErrors();
        $this->assertSame('80000.00', (string) $certificate->refresh()->total_amount);

        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->callAction(TestAction::make('invoice')->table(), ['method' => 'kaspi_invoice', 'amount' => '80000', 'invoice_number' => 'K-1']);
        $payment = $certificate->payments()->firstOrFail();
        $this->assertSame(PaymentStatus::Pending, $payment->status);

        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->callAction(TestAction::make('markPaid')->table($payment), ['paid_at' => now()]);

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->callAction('issue');

        $this->assertSame(CertificateStatus::Active, $certificate->refresh()->status);
        $this->get("/admin/certificates/{$certificate->id}")->assertOk()->assertSee($certificate->number);
    }

    public function test_single_sale_invoice_paid_and_issue_from_header(): void
    {
        $service = Service::factory()->create();
        app(ServicePricing::class)->addPrice($service, '1000', Carbon::parse('2026-01-01'));
        $certificate = app(CertificateManager::class)->createDraft([
            'package_id' => $this->emptyPackageId(),
            'customer_id' => Customer::factory()->create()->id,
            'recipient_id' => Recipient::factory()->create()->id,
        ]);
        app(CertificateManager::class)->addItem($certificate, $service, 1);

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->assertActionHidden('issue')->assertActionHidden('markPaid')
            ->assertActionVisible('invoice')
            ->callAction('invoice', ['method' => 'kaspi_invoice', 'amount' => '1000', 'invoice_number' => 'K-5']);
        $this->assertSame(PaymentStatus::Pending, $certificate->payments()->firstOrFail()->status);

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->assertActionHidden('invoice')->assertActionHidden('issue')
            ->callAction('markPaid', ['paid_at' => now()]);
        $this->assertTrue($certificate->refresh()->isFullyPaid());

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->assertActionHidden('markPaid')->assertActionHidden('invoice')
            ->callAction('issue');
        $this->assertSame(CertificateStatus::Active, $certificate->refresh()->status);
    }

    public function test_new_price_version_from_service_page(): void
    {
        $service = Service::factory()->create();

        Livewire::test(PricesRelationManager::class, ['ownerRecord' => $service, 'pageClass' => EditService::class])
            ->callAction(TestAction::make('addPrice')->table(), ['price' => '5000', 'valid_from' => '2026-01-01']);

        $this->assertSame('5000.00', (string) $service->prices()->first()->price);
    }

    public function test_demo_users_are_created_for_every_role_locally(): void
    {
        $this->artisan('gifter:demo-users')
            ->expectsQuestion('Общий пароль для тестовых пользователей (не короче 10 символов)', 'local-test-pass')
            ->assertSuccessful();

        $this->assertSame(Role::Sales, User::where('email', 'sales@gifter.local')->first()->roles->first());
        $this->assertSame(7, User::count());
    }
}
