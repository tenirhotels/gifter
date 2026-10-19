<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\CommissionStatus;
use App\Enums\CommissionType;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Pages\Commissions;
use App\Filament\Pages\MyCommission;
use App\Filament\Pages\SellerCommissions;
use App\Filament\Resources\CommissionRules\Pages\CreateCommissionRule;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificateCommission;
use App\Models\CommissionRule;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Recipient;
use App\Models\Service;
use App\Models\User;
use App\Services\AdminCorrections;
use App\Services\CertificateManager;
use App\Services\CommissionManager;
use App\Services\PaymentManager;
use App\Services\RefundManager;
use App\Services\ServicePricing;
use App\Support\Money;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Комиссия продавцов: начисляется при погашении, снимок на сертификате, согласование и выплата. */
class CommissionTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-15 12:00:00');
        $this->seller = User::factory()->create(['roles' => [Role::Sales], 'name' => 'Айгерим']);
        $service = Service::factory()->create(['name' => 'Проживание']);
        app(ServicePricing::class)->addPrice($service, '160000', Carbon::parse('2026-01-01'));
        $this->package = Package::create(['name' => 'Stay + Breakfast', 'validity_value' => 12, 'validity_unit' => 'months']);
        $this->package->items()->create(['service_id' => $service->id, 'quantity' => 1]);
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
    }

    private function rule(string $type = 'percent', string $value = '5', string $from = '2026-01-01', ?string $until = null): CommissionRule
    {
        return app(CommissionManager::class)->addRule([
            'package_id' => $this->package->id, 'type' => $type, 'rate' => $type === 'percent' ? $value : null,
            'fixed_amount' => $type === 'fixed' ? $value : null, 'valid_from' => $from, 'valid_until' => $until,
        ]);
    }

    private function sold(?User $by = null): Certificate
    {
        $this->actingAs($by ?? $this->seller);
        $manager = app(CertificateManager::class);
        $certificate = $manager->createDraft([
            'package_id' => $this->package->id, 'customer_id' => Customer::factory()->create()->id,
            'recipient_id' => Recipient::factory()->create(['last_name' => 'Иванов', 'first_name' => 'Иван'])->id,
        ]);
        $payments = app(PaymentManager::class);
        $payments->markPaid($payments->invoice($certificate, PaymentMethod::KaspiInvoice));

        return $manager->issue($certificate->refresh());
    }

    private function redeem(Certificate $certificate): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::FrontOffice]]));
        $this->reserve($certificate);
        app(CertificateManager::class)->transition($certificate->refresh(), CertificateStatus::InUse);
        app(CertificateManager::class)->transition($certificate->refresh(), CertificateStatus::Used);
    }

    public function test_owner_example_commission_is_earned_only_on_redemption(): void
    {
        $this->rule('percent', '5');
        $certificate = $this->sold();

        $commission = $certificate->commission;
        $this->assertSame($this->seller->id, $certificate->sold_by);
        $this->assertSame(CommissionStatus::PendingRedemption, $commission->status);
        $this->assertSame('8000.00', (string) $commission->amount, '160 000 × 5% — сумма посчитана, но не начислена');
        $this->assertSame(0, CertificateCommission::where('status', CommissionStatus::Earned)->count());

        $this->redeem($certificate);

        $this->assertSame(CommissionStatus::Earned, $commission->refresh()->status);
        $this->assertNotNull($commission->earned_at);
        $this->assertSame(1, AuditLog::where('event', 'commission_earned')->count());
    }

    public function test_snapshot_keeps_the_rule_of_the_sale_date(): void
    {
        $this->rule('percent', '5', '2026-01-01');
        $certificate = $this->sold();
        $this->rule('percent', '7', '2026-06-01');

        $this->assertNull(CommissionRule::where('rate', 5)->first()->valid_until === null ? null : null);
        $this->assertSame('2026-05-31', CommissionRule::where('rate', 5)->firstOrFail()->valid_until->toDateString(), 'Открытое правило закрыто днём до новой версии');

        Carbon::setTestNow('2026-06-20 12:00:00');
        $this->redeem($certificate);

        $commission = $certificate->commission->refresh();
        $this->assertSame(['8000.00', '5.00', CommissionType::Percent], [(string) $commission->amount, (string) $commission->rule_rate, $commission->rule_type],
            'Продана 15.05 по 5% — погашена после 01.06, но остаётся 5%');
        $this->assertSame('160000.00', (string) $commission->sale_price);

        $june = $this->sold();
        $this->assertSame('11200.00', (string) $june->commission->amount, 'Новая продажа — по 7%');
    }

    public function test_rule_validation_and_immutability(): void
    {
        $this->rule('percent', '5', '2026-01-01', '2026-05-31');
        $manager = app(CommissionManager::class);

        try {
            $manager->addRule(['package_id' => $this->package->id, 'type' => 'percent', 'rate' => 6, 'valid_from' => '2026-05-01']);
            $this->fail('Пересечение периодов');
        } catch (DomainRuleViolation $e) {
            $this->assertStringContainsString('пересекается', $e->getMessage());
        }
        foreach ([['rate' => 0], ['rate' => 101]] as $bad) {
            try {
                $manager->addRule(['package_id' => $this->package->id, 'type' => 'percent', 'valid_from' => '2027-01-01'] + $bad);
                $this->fail('Процент вне 0–100');
            } catch (DomainRuleViolation) {
            }
        }

        $rule = CommissionRule::firstOrFail();
        $this->expectException(DomainRuleViolation::class);
        $rule->update(['rate' => 9]);
    }

    public function test_fixed_rule_and_no_rule(): void
    {
        $this->assertNull($this->sold()->commission, 'Нет правила — комиссии нет');

        $this->rule('fixed', '15000');
        $this->assertSame('15000.00', (string) $this->sold()->commission->amount);
    }

    public function test_free_certificates_do_not_earn(): void
    {
        $this->rule();
        $free = Package::create(['name' => 'Блогер', 'validity_value' => 1, 'validity_unit' => 'months', 'is_complimentary' => true, 'complimentary_purpose' => 'influencers']);
        $free->items()->create(['service_id' => Service::first()->id, 'quantity' => 1]);
        $manager = app(CertificateManager::class);
        $certificate = $manager->createDraft(['package_id' => $free->id, 'complimentary_reason' => 'Блогер']);
        $manager->approveComplimentary($certificate);
        $manager->issue($certificate->refresh());

        $this->assertNull($certificate->refresh()->commission);
    }

    public function test_expired_and_cancelled_certificates_earn_nothing(): void
    {
        $this->rule();
        $expired = $this->sold();
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        Carbon::setTestNow('2028-01-01 12:00:00');
        app(CertificateManager::class)->expireOverdue();
        $this->assertSame(CommissionStatus::Void, $expired->commission->refresh()->status);

        Carbon::setTestNow('2026-05-15 12:00:00');
        $cancelled = $this->sold();
        $this->actingAs(User::factory()->create(['roles' => [Role::SalesManager]]));
        app(AdminCorrections::class);
        $payments = $cancelled->payments()->first();
        $refund = app(RefundManager::class)->request($cancelled->refresh(), '0', 'Гость передумал');
        $this->actingAs(User::factory()->create(['roles' => [Role::Finance]]));
        app(RefundManager::class)->approve($refund);
        $this->assertSame(CommissionStatus::Void, $cancelled->commission->refresh()->status);
        $this->assertNotNull($payments);
    }

    public function test_approve_pay_and_reverse_chain_with_audit(): void
    {
        $this->rule();
        $certificate = $this->sold();
        $this->redeem($certificate);
        $commission = $certificate->commission->refresh();
        $manager = app(CommissionManager::class);

        $this->actingAs(User::factory()->create(['roles' => [Role::Finance]]));
        try {
            $manager->pay($commission);
            $this->fail('Выплата без согласования директора');
        } catch (DomainRuleViolation) {
        }

        $this->actingAs(User::factory()->create(['roles' => [Role::SalesDirector]]));
        $manager->approve($commission);
        $this->assertSame(CommissionStatus::Approved, $commission->refresh()->status);

        $finance = User::factory()->create(['roles' => [Role::Finance]]);
        $this->actingAs($finance);
        $manager->pay($commission, Carbon::parse('2026-07-01'), 'ВЕД-17', 'за июнь');
        $payment = $commission->refresh()->payments()->sole();
        $this->assertSame([CommissionStatus::Paid, '8000.00', $finance->id, 'ВЕД-17'], [$commission->status, (string) $payment->amount, $payment->paid_by, $payment->reference]);

        $manager->reversePayment($commission, 'Ошибочная ведомость');
        $this->assertSame(CommissionStatus::Approved, $commission->refresh()->status);
        $this->assertSame('Ошибочная ведомость', $payment->refresh()->reversal_reason);
        $this->assertSame(1, $commission->payments()->count(), 'Запись выплаты не удаляется');

        $events = AuditLog::whereIn('event', ['commission_created', 'commission_earned', 'commission_approved', 'commission_paid', 'commission_payment_made', 'commission_payment_reversed', 'commission_rule_added'])->pluck('event')->unique()->sort()->values()->all();
        $this->assertSame(['commission_approved', 'commission_created', 'commission_earned', 'commission_paid', 'commission_payment_made', 'commission_payment_reversed', 'commission_rule_added'], $events);
    }

    public function test_permissions_by_role(): void
    {
        $this->rule();
        $certificate = $this->sold();
        $this->redeem($certificate);
        $commission = $certificate->commission->refresh();

        $this->actingAs($this->seller);
        $this->get('/admin/my-commission')->assertOk();
        $this->get('/admin/commissions')->assertForbidden();
        $this->get('/admin/commission-rules/create')->assertForbidden();

        foreach ([Role::Finance, Role::SalesDirector] as $role) {
            $this->actingAs(User::factory()->create(['roles' => [$role]]));
            $this->get('/admin/commissions')->assertOk();
            $this->get('/admin/my-commission')->assertForbidden();
        }

        $this->actingAs(User::factory()->create(['roles' => [Role::SalesDirector]]));
        Livewire::test(SellerCommissions::class, ['seller' => $this->seller->id])
            ->assertCanSeeTableRecords([$commission])
            ->assertActionVisible(TestAction::make('approve')->table($commission))
            ->assertActionHidden(TestAction::make('pay')->table($commission));
        $this->actingAs(User::factory()->create(['roles' => [Role::Finance]]));
        Livewire::test(SellerCommissions::class, ['seller' => $this->seller->id])
            ->assertActionHidden(TestAction::make('approve')->table($commission));
    }

    public function test_seller_sees_only_own_data_and_summary_counts(): void
    {
        $this->rule();
        $other = User::factory()->create(['roles' => [Role::Sales]]);
        $redeemed = $this->sold();
        $this->redeem($redeemed);
        $noReservation = $this->sold();
        $reserved = $this->sold();
        $this->actingAs(User::factory()->create(['roles' => [Role::FrontOffice]]));
        $this->reserve($reserved);
        $foreign = $this->sold($other);

        $row = app(CommissionManager::class)->summary($this->seller->id)[$this->seller->id];
        $this->assertSame([3, 1, 2, 1, '8000.00', '8000.00'], [$row['sold'], $row['redeemed'], $row['not_redeemed'], $row['no_reservation'],
            Money::fromMinor($row['earned_minor']), Money::fromMinor($row['pending_minor'])]);
        $this->assertSame(160000 * 100 * 3, $row['sales_minor']);

        $this->actingAs($this->seller);
        Livewire::test(MyCommission::class)->assertCanSeeTableRecords([$redeemed, $noReservation, $reserved])->assertCanNotSeeTableRecords([$foreign]);
        $this->actingAs(User::factory()->create(['roles' => [Role::Finance]]));
        Livewire::test(Commissions::class)->assertSee('Айгерим')->assertCanSeeTableRecords([$this->seller, $other]);
    }

    public function test_seller_is_assigned_by_manager_in_draft_and_locked_after_issue(): void
    {
        $this->rule();
        $manager = app(CertificateManager::class);
        $this->actingAs(User::factory()->create(['roles' => [Role::Sales]]));
        $certificate = $manager->createDraft(['package_id' => $this->package->id, 'customer_id' => Customer::factory()->create()->id]);
        $this->assertSame(auth()->id(), $certificate->sold_by);

        try {
            $manager->createDraft(['package_id' => $this->package->id, 'customer_id' => Customer::factory()->create()->id, 'sold_by' => $this->seller->id]);
            $this->fail('Продавец не может назначить другого');
        } catch (DomainRuleViolation) {
        }

        $this->actingAs(User::factory()->create(['roles' => [Role::SalesManager]]));
        $manager->setSeller($certificate, $this->seller->id);
        $this->assertSame($this->seller->id, $certificate->refresh()->sold_by);

        $payments = app(PaymentManager::class);
        $payments->markPaid($payments->invoice($certificate, PaymentMethod::KaspiInvoice));
        $manager->issue($certificate->refresh());
        $this->assertSame($this->seller->id, $certificate->commission->seller_id);

        $this->expectException(DomainRuleViolation::class);
        $manager->setSeller($certificate->refresh(), auth()->id());
    }

    public function test_admin_cannot_roll_back_redemption_after_approval(): void
    {
        $this->rule();
        $certificate = $this->sold();
        $this->redeem($certificate);
        $corrections = app(AdminCorrections::class);

        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $corrections->setStatus($certificate->refresh(), CertificateStatus::Active, 'Погашен по ошибке');
        $this->assertSame(CommissionStatus::PendingRedemption, $certificate->commission->refresh()->status);

        $corrections->setStatus($certificate->refresh(), CertificateStatus::Used, 'Всё же погашен');
        $this->actingAs(User::factory()->create(['roles' => [Role::SalesDirector]]));
        app(CommissionManager::class)->approve($certificate->commission->refresh());

        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $this->expectException(DomainRuleViolation::class);
        $corrections->setStatus($certificate->refresh(), CertificateStatus::Active, 'Ещё раз');
    }

    public function test_rule_form_creates_new_version(): void
    {
        $this->rule('percent', '5', '2026-01-01');
        Livewire::test(CreateCommissionRule::class)
            ->fillForm(['package_id' => $this->package->id, 'type' => 'percent', 'rate' => 7, 'valid_from' => '2026-06-01'])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame(2, CommissionRule::count());
        Livewire::test(CreateCommissionRule::class)
            ->fillForm(['package_id' => $this->package->id, 'type' => 'percent', 'rate' => 9, 'valid_from' => '2026-07-01', 'valid_until' => '2026-07-10'])
            ->call('create');
        $this->assertSame(2, CommissionRule::count(), 'Пересечение с открытой версией отклонено');
    }
}
