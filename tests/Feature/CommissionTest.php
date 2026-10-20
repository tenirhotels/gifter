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
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Filament\Resources\CommissionRules\Pages\CreateCommissionRule;
use App\Filament\Widgets\DirectorQueueWidget;
use App\Filament\Widgets\MySalesWidget;
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
use App\Services\ReservationExceptionManager;
use App\Services\SellerReport;
use App\Services\ServicePricing;
use App\Support\Money;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
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
        $admin = User::factory()->create(['roles' => [Role::SuperAdmin]]);
        $this->actingAs($admin);
        Livewire::test(MyCommission::class)->assertSeeLivewire(MySalesWidget::class);
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
        Livewire::test(MyCommission::class)->assertCanSeeTableRecords([$redeemed, $noReservation, $reserved])->assertCanNotSeeTableRecords([$foreign])
            ->assertSee(['Дата продажи', 'Стоимость', 'Бронирование', 'Комиссионные', 'Выплата', 'Бронь не создана', 'ожидается', 'начислено']);
        $this->actingAs(User::factory()->create(['roles' => [Role::Finance]]));
        Livewire::test(Commissions::class)->assertSee('Айгерим')->assertSee($other->name);
    }

    public function test_commissions_are_filtered_by_redemption_period(): void
    {
        $this->rule();
        $old = $this->sold();
        $this->redeem($old);
        $new = $this->sold();
        $this->redeem($new);
        $old->commission->update(['earned_at' => now()->subMonthsNoOverflow(2)]);

        $manager = app(CommissionManager::class);
        $thisMonth = $manager->summary($this->seller->id, now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString())[$this->seller->id];
        $this->assertSame('8000.00', Money::fromMinor($thisMonth['earned_minor']));
        $this->assertSame(2, $manager->summary($this->seller->id)[$this->seller->id]['redeemed']);

        $this->actingAs($this->seller);
        Livewire::test(MyCommission::class)->assertSee('Мои продажи')
            ->filterTable('period', ['preset' => 'this'])
            ->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$old]);
        $this->actingAs(User::factory()->create(['roles' => [Role::Finance]]));
        Livewire::test(SellerCommissions::class, ['seller' => $this->seller->id])
            ->filterTable('period', ['preset' => 'last'])
            ->assertCountTableRecords(0);
    }

    public function test_my_sales_tiles_follow_month_and_year_and_flag_missing_reservations(): void
    {
        $this->rule();
        $redeemed = $this->sold();
        $this->redeem($redeemed);
        $this->sold();
        $redeemed->update(['sold_at' => now()->subMonthsNoOverflow(2)]);
        $redeemed->commission->update(['earned_at' => now()->subMonthsNoOverflow(2)]);

        $this->actingAs($this->seller);
        $month = Livewire::test(MySalesWidget::class)->assertSee('Месяц')->assertSee('Год')->assertSeeHtml('mountAction')->assertSee('Требуют внимания')->assertSee('без брони: 1')->assertSee('Ожидается');
        $month->assertSee('Продано');
        $this->assertSame('month', $month->get('period'));
        $month->set('period', 'year')->assertSee(now()->format('Y').' год');
    }

    public function test_certificate_card_explains_commission_state(): void
    {
        $this->rule();
        $certificate = $this->sold();
        $this->actingAs($this->seller);

        $page = fn () => $this->get(CertificateResource::getUrl('view', ['record' => $certificate]));
        $page()->assertOk()->assertSee('Стоимость и комиссия')->assertSee('Комиссия за продажу')->assertSee('Комиссия:')
            ->assertSee('Ожидает использования сертификата')->assertSee('Комиссия будет начислена после использования сертификата');

        $this->redeem($certificate);
        $this->actingAs($this->seller);
        $page()->assertSee('Комиссия начислена');
    }

    public function test_seller_assigned_by_manager_can_open_the_certificate(): void
    {
        $this->rule();
        $certificate = $this->sold();
        $creator = User::factory()->create(['roles' => [Role::Sales]]);
        $certificate->update(['created_by' => $creator->id]);
        $stranger = User::factory()->create(['roles' => [Role::Sales]]);

        $this->actingAs($this->seller)->get(CertificateResource::getUrl('view', ['record' => $certificate]))->assertOk();
        $this->actingAs($stranger)->get(CertificateResource::getUrl('view', ['record' => $certificate]))->assertNotFound();
    }

    public function test_sale_date_and_seller_changes_need_a_reason_and_reach_the_director(): void
    {
        $manager = app(CertificateManager::class);
        $this->actingAs(User::factory()->create(['roles' => [Role::SalesManager]]));
        $certificate = $manager->createDraft(['package_id' => $this->package->id, 'customer_id' => Customer::factory()->create()->id]);

        foreach ([fn () => $manager->updateDraft($certificate, ['sold_at' => '2026-09-01']),
            fn () => $manager->updateDraft($certificate, ['sold_at' => '2026-09-01', 'sold_at_reason' => 'ок']),
            fn () => $manager->setSeller($certificate, $this->seller->id)] as $withoutReason) {
            try {
                $withoutReason();
                $this->fail('Изменение без причины');
            } catch (DomainRuleViolation $e) {
                $this->assertStringContainsString('причину', $e->getMessage());
            }
        }

        $manager->updateDraft($certificate, ['sold_at' => '2026-09-01', 'sold_at_reason' => 'Продажа по договору от 01.09']);
        $manager->setSeller($certificate, $this->seller->id, reason: 'Клиента привёл другой менеджер');

        $this->assertSame(['sale_date_overridden', 'seller_overridden'], AuditLog::whereIn('event', ['sale_date_overridden', 'seller_overridden'])->orderBy('id')->pluck('event')->all());
        $this->actingAs(User::factory()->create(['roles' => [Role::SalesDirector]]));
        Livewire::test(DirectorQueueWidget::class)->assertSee('Ручные изменения продаж');
    }

    public function test_director_price_waits_for_admin_approval(): void
    {
        config(['gifter.require_price_approval' => true]);
        $service = Service::factory()->create();
        $pricing = app(ServicePricing::class);
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $old = $pricing->addPrice($service, '40000', Carbon::parse('2026-01-01'));
        $this->assertTrue($old->isApproved(), 'Администратор подтверждает сразу');

        $this->actingAs(User::factory()->create(['roles' => [Role::SalesDirector]]));
        $new = $pricing->addPrice($service, '50000', Carbon::parse('2026-11-01'));

        $this->assertFalse($new->isApproved());
        $this->assertSame('40000.00', (string) $pricing->priceOn($service, Carbon::parse('2026-11-05'))->price, 'Пока не подтверждена — действует прежняя');
        $this->assertNull($old->refresh()->valid_until);
        try {
            $pricing->approve($new);
            $this->fail('Директор не подтверждает цену');
        } catch (DomainRuleViolation) {
        }

        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $pricing->approve($new);
        $this->assertSame('50000.00', (string) $pricing->priceOn($service, Carbon::parse('2026-11-05'))->price);
        $this->assertSame('2026-10-31', $old->refresh()->valid_until->toDateString());
    }

    public function test_seller_is_assigned_by_manager_in_draft_and_locked_after_issue(): void
    {
        $this->rule();
        $manager = app(CertificateManager::class);
        $this->actingAs(User::factory()->create(['roles' => [Role::Sales]]));
        $certificate = $manager->createDraft(['package_id' => $this->package->id, 'customer_id' => Customer::factory()->create()->id]);
        $this->assertSame(auth()->id(), $certificate->sold_by);

        try {
            $manager->createDraft(['package_id' => $this->package->id, 'customer_id' => Customer::factory()->create()->id, 'sold_by' => $this->seller->id, 'seller_reason' => 'Клиента привёл другой менеджер']);
            $this->fail('Продавец не может назначить другого');
        } catch (DomainRuleViolation) {
        }

        $this->actingAs(User::factory()->create(['roles' => [Role::SalesManager]]));
        $manager->setSeller($certificate, $this->seller->id, reason: 'Передан другому менеджеру');
        $this->assertSame($this->seller->id, $certificate->refresh()->sold_by);

        $payments = app(PaymentManager::class);
        $payments->markPaid($payments->invoice($certificate, PaymentMethod::KaspiInvoice));
        $manager->issue($certificate->refresh());
        $this->assertSame($this->seller->id, $certificate->commission->seller_id);

        $this->expectException(DomainRuleViolation::class);
        $manager->setSeller($certificate->refresh(), auth()->id(), reason: 'Вернули автору');
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

    public function test_expired_without_booking_returns_by_director_exception_and_commission_follows_redemption(): void
    {
        $this->rule();
        $certificate = $this->sold();
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        Carbon::setTestNow('2027-06-01 12:00:00');
        app(CertificateManager::class)->expireOverdue();
        $this->assertSame(CertificateStatus::Expired, $certificate->refresh()->status);
        $this->assertSame(CommissionStatus::Void, $certificate->commission->refresh()->status);

        $manager = app(ReservationExceptionManager::class);
        $data = ['booking_number' => 'PMS-77', 'guest_name' => 'Иванов Иван', 'arrival' => '2027-06-10', 'departure' => '2027-06-12', 'reason' => 'Гость не успел, номера свободны'];

        $this->actingAs(User::factory()->create(['roles' => [Role::FrontOffice]]));
        $this->get('/admin/reservation-exceptions')->assertOk();
        $request = $manager->request($certificate->refresh(), $data);
        $this->assertTrue($certificate->hasPendingException());
        try {
            $manager->request($certificate, $data);
            $this->fail('Вторая заявка при ожидающей');
        } catch (DomainRuleViolation) {
        }
        $this->assertSame(CertificateStatus::Expired, $certificate->refresh()->status, 'До согласования сертификат не меняется');

        $this->actingAs(User::factory()->create(['roles' => [Role::SalesDirector]]));
        $manager->approve($request, 'Ок');

        $certificate->refresh();
        $this->assertSame([CertificateStatus::Reserved, 'PMS-77', '2027-06-12'], [$certificate->status, $certificate->pms_booking_number, $certificate->valid_until->toDateString()]);
        $this->assertNull($certificate->reservation_review_status, 'Финансы не перепроверяют поздний срок — решение принял директор');
        $this->assertSame(CommissionStatus::PendingRedemption, $certificate->commission->refresh()->status, 'Комиссия не потеряна и не начислена до погашения');

        Carbon::setTestNow('2027-06-10 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::FrontOffice]]));
        app(CertificateManager::class)->transition($certificate->refresh(), CertificateStatus::InUse);
        app(CertificateManager::class)->transition($certificate->refresh(), CertificateStatus::Used);
        $this->assertSame(CommissionStatus::Earned, $certificate->commission->refresh()->status);
        $this->assertSame(1, AuditLog::where('event', 'reservation_exception_approved')->count());
    }

    public function test_exception_can_be_rejected_and_is_only_for_expired(): void
    {
        $this->rule();
        $active = $this->sold();
        $manager = app(ReservationExceptionManager::class);
        $data = ['booking_number' => 'PMS-9', 'guest_name' => 'Иванов Иван', 'arrival' => '2026-06-10', 'departure' => '2026-06-12', 'reason' => 'x'];
        try {
            $manager->request($active, $data);
            $this->fail('Активный сертификат — обычная бронь');
        } catch (DomainRuleViolation) {
        }

        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        Carbon::setTestNow('2027-06-01 12:00:00');
        app(CertificateManager::class)->expireOverdue();
        $request = $manager->request($active->refresh(), ['arrival' => '2027-06-10', 'departure' => '2027-06-12'] + $data);
        $this->actingAs(User::factory()->create(['roles' => [Role::SalesDirector]]));
        try {
            $manager->reject($request, ' ');
            $this->fail('Отказ без причины');
        } catch (DomainRuleViolation) {
        }
        $manager->reject($request, 'Нет свободных номеров');
        $this->assertSame(CertificateStatus::Expired, $active->refresh()->status);
        $this->assertSame(CommissionStatus::Void, $active->commission->refresh()->status);
    }

    public function test_exception_actions_on_the_certificate_card(): void
    {
        $this->rule();
        $certificate = $this->sold();
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        Carbon::setTestNow('2027-06-01 12:00:00');
        app(CertificateManager::class)->expireOverdue();

        $this->actingAs(User::factory()->create(['roles' => [Role::FrontOffice]]));
        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->assertActionVisible('requestException')->assertActionHidden('approveException')
            ->callAction('requestException', ['booking_number' => 'PMS-5', 'guest_name' => 'Иванов Иван', 'arrival' => '2027-06-10', 'departure' => '2027-06-12', 'reason' => 'Свободны номера'])
            ->assertHasNoActionErrors();
        $this->assertTrue($certificate->refresh()->hasPendingException());

        $this->actingAs(User::factory()->create(['roles' => [Role::SalesDirector]]));
        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->assertActionHidden('requestException')->assertActionVisible('approveException')
            ->callAction('approveException', ['comment' => 'Ок']);
        $this->assertSame(CertificateStatus::Reserved, $certificate->refresh()->status);
    }

    public function test_rule_form_shows_percent_field_by_default_and_amount_for_fixed(): void
    {
        Livewire::test(CreateCommissionRule::class)
            ->assertFormFieldVisible('rate')->assertFormFieldHidden('fixed_amount')
            ->fillForm(['type' => 'fixed'])
            ->assertFormFieldVisible('fixed_amount')->assertFormFieldHidden('rate')
            ->fillForm(['package_id' => $this->package->id, 'fixed_amount' => 15000, 'valid_from' => '2026-01-01'])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame('15000.00', (string) CommissionRule::sole()->fixed_amount);
    }

    public function test_seller_report_summary_by_usage_and_sale_with_period_and_seller_filters(): void
    {
        $this->rule('percent', '5');
        $other = User::factory()->create(['roles' => [Role::Sales], 'name' => 'Бауыржан']);
        $used = $this->sold();
        $this->sold($other);
        $this->redeem($used);

        $byUsage = (new SellerReport(SellerReport::USED, '2026-05-01', '2026-05-31'))->summary();
        $this->assertCount(1, $byUsage);
        $this->assertSame('Айгерим', $byUsage[0]['seller']);
        $this->assertSame(1, $byUsage[0]['used']);
        $this->assertSame(800000, $byUsage[0]['earned_minor']);

        $bySale = (new SellerReport(SellerReport::SOLD, '2026-05-01', '2026-05-31'))->summary();
        $this->assertCount(2, $bySale);
        $this->assertSame(1, $bySale->firstWhere('seller', 'Бауыржан')['not_used']);
        $this->assertSame(0, $bySale->firstWhere('seller', 'Бауыржан')['earned_minor']);

        $this->assertCount(0, (new SellerReport(SellerReport::SOLD, '2026-06-01', '2026-06-30'))->summary());
        $this->assertCount(1, (new SellerReport(SellerReport::SOLD, null, null, $other->id))->summary());

        $months = (new SellerReport(SellerReport::USED))->months();
        $this->assertSame(['2026-05'], $months['months']);
        $this->assertSame(800000, $months['rows'][0]['total']);
    }

    public function test_seller_report_excel_has_three_sheets_and_page_access_and_audit(): void
    {
        $this->rule('percent', '5');
        $certificate = $this->sold();
        $this->redeem($certificate);

        $path = (new SellerReport(SellerReport::USED))->toXlsx();
        $reader = new Reader;
        $reader->open($path);
        $names = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $names[] = $sheet->getName();
            if ($sheet->getName() === 'Сертификаты') {
                $rows = iterator_to_array($sheet->getRowIterator(), false);
                $this->assertSame($certificate->number, $rows[1]->toArray()[1]);
            }
        }
        $reader->close();
        @unlink($path);
        $this->assertSame(['Сводка', 'Сертификаты', 'По месяцам'], $names);

        $this->actingAs($this->seller);
        $this->get('/admin/commissions')->assertForbidden();
        $this->actingAs(User::factory()->create(['roles' => [Role::Finance]]));
        Livewire::test(Commissions::class)->callAction('export')->assertFileDownloaded();
        $this->assertDatabaseHas('audit_logs', ['event' => 'seller_report_exported']);
    }

    public function test_commissions_page_is_the_seller_report_with_slices(): void
    {
        $this->rule('percent', '5');
        $other = User::factory()->create(['roles' => [Role::Sales], 'name' => 'Бауыржан']);
        $used = $this->sold();
        $this->sold($other);
        $this->redeem($used);
        $this->actingAs(User::factory()->create(['roles' => [Role::Finance]]));

        $page = Livewire::test(Commissions::class)->assertSee('Без брони')->assertSee('К согласованию');
        $this->assertCount(2, $page->instance()->report()->summary());
        $page->set('data.basis', 'used');
        $this->assertCount(1, $page->instance()->report()->summary());
        $page->set('data.preset', 'last');
        $this->assertCount(0, $page->instance()->report()->summary());
        $page->set('data.preset', 'all')->assertSee('Начисленная комиссия по месяцам');
    }
}
