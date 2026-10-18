<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Filament\Widgets\AdminOverviewWidget;
use App\Filament\Widgets\DirectorQueueWidget;
use App\Filament\Widgets\DraftsWidget;
use App\Filament\Widgets\ExpiringCertificatesWidget;
use App\Filament\Widgets\FinanceQueueWidget;
use App\Filament\Widgets\FrontOfficeQueueWidget;
use App\Filament\Widgets\SalesQueueWidget;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\CertificateManager;
use App\Services\PaymentManager;
use App\Services\ServicePricing;
use App\Support\DashboardQueries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    private function draft(array $data = [])
    {
        $certificate = app(CertificateManager::class)->createDraft($data + [
            'package_id' => $this->emptyPackageId(),
            'customer_id' => Customer::factory()->create()->id,
        ]);
        app(CertificateManager::class)->addItem($certificate, $this->service, 1);

        return $certificate->refresh();
    }

    public static function roleHeadings(): array
    {
        return [
            'sales' => [Role::Sales, [SalesQueueWidget::class, DraftsWidget::class, ExpiringCertificatesWidget::class], [FinanceQueueWidget::class, DirectorQueueWidget::class]],
            'manager' => [Role::SalesManager, [SalesQueueWidget::class, DraftsWidget::class], [DirectorQueueWidget::class, FinanceQueueWidget::class]],
            'director' => [Role::SalesDirector, [DirectorQueueWidget::class], [SalesQueueWidget::class, DraftsWidget::class]],
            'front office' => [Role::FrontOffice, [FrontOfficeQueueWidget::class, ExpiringCertificatesWidget::class], [SalesQueueWidget::class, FinanceQueueWidget::class]],
            'finance' => [Role::Finance, [FinanceQueueWidget::class], [SalesQueueWidget::class, ExpiringCertificatesWidget::class]],
            'admin' => [Role::SuperAdmin, [AdminOverviewWidget::class], [SalesQueueWidget::class, FinanceQueueWidget::class]],
        ];
    }

    #[DataProvider('roleHeadings')]
    public function test_each_role_sees_its_own_queue(Role $role, array $sees, array $doesNotSee): void
    {
        $this->as($role);
        // Таблицы на главной видны только когда в них есть строки.
        $certificate = $this->draft();
        app(PaymentManager::class)->markPaid(app(PaymentManager::class)->invoice($certificate, PaymentMethod::KaspiInvoice));
        app(CertificateManager::class)->issue($certificate);
        app(CertificateManager::class)->changeValidity($certificate, Carbon::parse('2026-10-18'), Carbon::parse('2026-10-25'), 'тест');
        $this->draft();
        $response = $this->get('/admin')->assertOk();
        foreach ($sees as $widget) {
            $response->assertSeeLivewire($widget);
        }
        foreach ($doesNotSee as $widget) {
            $response->assertDontSeeLivewire($widget);
        }
    }

    public function test_empty_tables_are_hidden(): void
    {
        $this->as(Role::Sales);
        $this->get('/admin')->assertOk()
            ->assertSeeLivewire(SalesQueueWidget::class)
            ->assertDontSeeLivewire(DraftsWidget::class)
            ->assertDontSeeLivewire(ExpiringCertificatesWidget::class);
    }

    public function test_sales_queue_counts_only_own_certificates(): void
    {
        $seller = $this->as(Role::Sales);
        $own = $this->draft();
        app(PaymentManager::class)->invoice($own, PaymentMethod::KaspiInvoice);
        $this->as(Role::Sales);
        $this->draft();

        $this->actingAs($seller);
        $q = new DashboardQueries($seller);
        $this->assertSame(1, $q->drafts()->count());
        $this->assertSame(1, $q->awaitingPayment()->count());
        Livewire::test(SalesQueueWidget::class)->assertSee('Ждут оплаты')->assertSee('Готовы к выпуску');
    }

    public function test_ready_to_issue_counts_paid_drafts_and_approved_complimentary(): void
    {
        $paid = $this->draft();
        app(PaymentManager::class)->markPaid(app(PaymentManager::class)->invoice($paid, PaymentMethod::KaspiInvoice));
        $this->draft();
        $gift = $this->draft(['package_id' => $this->complimentaryPackageId(), 'complimentary_reason' => 'Партнёр']);
        app(CertificateManager::class)->approveComplimentary($gift);

        $this->assertEqualsCanonicalizing([$paid->id, $gift->id],
            (new DashboardQueries(auth()->user()))->readyToIssue()->pluck('id')->all());
    }

    public function test_finance_and_director_queues(): void
    {
        $certificate = $this->draft();
        app(PaymentManager::class)->markPaid(app(PaymentManager::class)->invoice($certificate, PaymentMethod::KaspiInvoice));
        app(CertificateManager::class)->issue($certificate);
        $this->draft(['package_id' => $this->complimentaryPackageId(), 'complimentary_reason' => 'Партнёр']);
        Service::factory()->create(['name' => 'Без цены']);

        $q = new DashboardQueries(auth()->user());
        $this->assertSame(1, $q->paymentsToReconcile()->count());
        $this->assertSame(1, $q->complimentaryAwaitingApproval()->count());
        $this->assertSame(1, $q->servicesWithoutPrice()->count());
        $this->assertSame(1, $q->servicesWithoutCost()->count());

        $this->as(Role::Finance);
        Livewire::test(FinanceQueueWidget::class)->assertSee('Ждут сверки')->assertSee('10 000 ₸');
        $this->as(Role::SalesDirector);
        Livewire::test(DirectorQueueWidget::class)->assertSee('Бесплатные сертификаты ждут согласования')->assertSee('Услуги без цены на сегодня');
    }

    public function test_expiring_filter_link_works(): void
    {
        $certificate = $this->draft();
        app(PaymentManager::class)->markPaid(app(PaymentManager::class)->invoice($certificate, PaymentMethod::KaspiInvoice));
        app(CertificateManager::class)->issue($certificate);
        app(CertificateManager::class)->changeValidity($certificate, Carbon::parse('2026-10-18'), Carbon::parse('2026-10-25'), 'тест');
        $other = $this->draft();

        $this->get('/admin/certificates?filters[expiring][isActive]=1')->assertOk()
            ->assertSee($certificate->number)->assertDontSee($other->number);
    }
}
