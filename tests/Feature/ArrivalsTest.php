<?php

namespace Tests\Feature;

use App\Enums\ArrivalReadinessLevel;
use App\Enums\CertificateStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\NameMatch;
use App\Enums\PaymentMethod;
use App\Enums\ReservationReview;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Pages\ReservationReviews;
use App\Filament\Pages\UpcomingArrivals;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Recipient;
use App\Models\Service;
use App\Models\User;
use App\Services\CertificateManager;
use App\Services\FulfillmentManager;
use App\Services\PaymentManager;
use App\Services\ReservationManager;
use App\Services\ServicePricing;
use App\Support\ArrivalReadiness;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Фаза 6: бронь с номером PMS, сверка имени, проверка Финансов, ближайшие заезды. */
class ArrivalsTest extends TestCase
{
    use RefreshDatabase;

    private ReservationManager $reservations;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $this->reservations = app(ReservationManager::class);
    }

    private function as(Role ...$roles): User
    {
        $user = User::factory()->create(['roles' => $roles]);
        $this->actingAs($user);

        return $user;
    }

    private function active(bool $withDinner = false, string $validity = '6'): Certificate
    {
        $pricing = app(ServicePricing::class);
        $room = Service::factory()->create(['name' => 'Номер']);
        $pricing->addPrice($room, '50000', Carbon::parse('2026-01-01'));
        $package = Package::create(['name' => 'Уикенд '.uniqid(), 'validity_value' => (int) $validity, 'validity_unit' => $validity === '10' ? 'days' : 'months']);
        $package->items()->create(['service_id' => $room->id, 'quantity' => 1]);
        if ($withDinner) {
            $dinner = Service::factory()->create(['name' => 'Ужин', 'requires_fulfillment' => true, 'requires_confirmation' => true]);
            $pricing->addPrice($dinner, '10000', Carbon::parse('2026-01-01'));
            $package->items()->create(['service_id' => $dinner->id, 'quantity' => 2]);
        }
        $certificates = app(CertificateManager::class);
        $certificate = $certificates->createDraft([
            'package_id' => $package->id,
            'customer_id' => Customer::factory()->create(['last_name' => 'Сарсенов', 'first_name' => 'Ерлан'])->id,
            'recipient_id' => Recipient::factory()->create(['last_name' => 'Койчиманов', 'first_name' => 'Нуржан'])->id,
        ]);
        $payments = app(PaymentManager::class);
        $payments->markPaid($payments->invoice($certificate, PaymentMethod::KaspiInvoice));

        return $certificates->issue($certificate->refresh());
    }

    /** @return array<string, mixed> */
    private function booking(string $guest = 'KOICHIMANOV NURZHAN', array $extra = []): array
    {
        return $extra + ['booking_number' => 'R-1001', 'guest_name' => $guest, 'arrival' => '2026-10-20', 'departure' => '2026-10-22'];
    }

    public function test_reservation_with_matching_recipient_name(): void
    {
        $certificate = $this->reservations->save($this->active(), $this->booking());

        $this->assertSame(CertificateStatus::Reserved, $certificate->status);
        $this->assertSame([NameMatch::Recipient, null], [$certificate->name_match, $certificate->reservation_review_status]);
        $this->assertSame('№ R-1001 · 20.10–22.10.2026 · KOICHIMANOV NURZHAN', $certificate->reservationLabel());
        $this->assertTrue($certificate->history()->where('description', 'like', '№ R-1001%Совпадает с получателем%')->exists());
    }

    public function test_customer_name_also_matches(): void
    {
        $certificate = $this->reservations->save($this->active(), $this->booking('Yerlan Sarsenov'));

        $this->assertSame(NameMatch::Customer, $certificate->name_match);
        $this->assertNull($certificate->reservation_review_status);
    }

    public function test_mismatch_requires_reason_and_goes_to_finance(): void
    {
        $certificate = $this->active();
        try {
            $this->reservations->save($certificate, $this->booking('Петров Иван'));
            $this->fail('Несовпадение без причины');
        } catch (DomainRuleViolation $e) {
            $this->assertStringContainsString('укажите причину', $e->getMessage());
        }

        $this->reservations->save($certificate, $this->booking('Петров Иван', ['note' => 'Подарил другу, заказчик подтвердил по телефону']));
        $this->assertSame([NameMatch::Mismatch, ReservationReview::Pending], [$certificate->name_match, $certificate->reservation_review_status]);
    }

    public function test_reservation_in_last_days_of_validity_goes_to_review(): void
    {
        $certificate = $this->active(validity: '10');
        $this->expectException(DomainRuleViolation::class);
        $this->reservations->save($certificate, $this->booking());
    }

    public function test_late_reservation_with_comment_is_reviewed(): void
    {
        $certificate = $this->active(validity: '10');
        $this->reservations->save($certificate, $this->booking(extra: ['note' => 'Гость позвонил поздно']));

        $this->assertSame(ReservationReview::Pending, $certificate->refresh()->reservation_review_status);
    }

    public function test_dates_and_booking_number_rules(): void
    {
        $certificate = $this->active();
        foreach ([
            $this->booking(extra: ['arrival' => '2026-10-22', 'departure' => '2026-10-22']),
            $this->booking(extra: ['arrival' => '2027-06-01', 'departure' => '2027-06-03']),
            $this->booking(extra: ['arrival' => '2026-10-10', 'departure' => '2026-10-12']),
            $this->booking(extra: ['booking_number' => ' ']),
        ] as $i => $bad) {
            try {
                $this->reservations->save($certificate, $bad);
                $this->fail("Правило {$i}");
            } catch (DomainRuleViolation) {
            }
        }

        $this->reservations->save($certificate, $this->booking());
        $this->expectExceptionMessage('уже привязана к сертификату');
        $this->reservations->save($this->active(), $this->booking());
    }

    public function test_direct_status_changes_are_closed(): void
    {
        $certificate = $this->active();
        foreach ([fn () => app(CertificateManager::class)->transition($certificate, CertificateStatus::Reserved),
            fn () => app(CertificateManager::class)->transition($certificate, CertificateStatus::InUse)] as $i => $bad) {
            try {
                $bad();
                $this->fail("Переход {$i}");
            } catch (DomainRuleViolation) {
            }
        }
        $this->assertSame(CertificateStatus::Active, $certificate->refresh()->status);
    }

    public function test_check_in_not_earlier_than_day_before_arrival(): void
    {
        $certificate = $this->reservations->save($this->active(), $this->booking(extra: ['arrival' => '2026-10-25', 'departure' => '2026-10-27']));
        try {
            app(CertificateManager::class)->transition($certificate, CertificateStatus::InUse);
            $this->fail('Заезд за неделю до брони');
        } catch (DomainRuleViolation) {
        }

        Carbon::setTestNow('2026-10-24 15:00:00');
        app(CertificateManager::class)->transition($certificate->refresh(), CertificateStatus::InUse);
        $this->assertSame(CertificateStatus::InUse, $certificate->status);
    }

    public function test_release_clears_reservation_but_keeps_history(): void
    {
        $certificate = $this->reservations->save($this->active(), $this->booking());
        $this->reservations->release($certificate, 'Гость перенёс');

        $this->assertSame(CertificateStatus::Active, $certificate->refresh()->status);
        $this->assertNull($certificate->pms_booking_number);
        $this->assertTrue($certificate->history()->where('description', 'like', '№ R-1001%Гость перенёс')->exists());
    }

    public function test_recipient_cannot_be_swapped_while_reserved(): void
    {
        $certificate = $this->reservations->save($this->active(), $this->booking());

        $this->expectExceptionMessage('только после снятия брони');
        app(CertificateManager::class)->setRecipient($certificate, Recipient::factory()->create()->id);
    }

    public function test_finance_dispute_blocks_redemption_and_approve_unblocks(): void
    {
        $certificate = $this->reservations->save($this->active(), $this->booking('Петров Иван', ['note' => 'друг', 'arrival' => '2026-10-19']));
        app(CertificateManager::class)->transition($certificate, CertificateStatus::InUse);

        $this->as(Role::Finance);
        Livewire::test(ReservationReviews::class)
            ->assertCanSeeTableRecords([$certificate])
            ->callAction(TestAction::make('dispute')->table($certificate), ['comment' => 'Гость не подтверждён']);
        $this->assertSame(ReservationReview::Disputed, $certificate->refresh()->reservation_review_status);

        try {
            app(CertificateManager::class)->transition($certificate, CertificateStatus::Used);
            $this->fail('Погашение оспоренной брони');
        } catch (DomainRuleViolation) {
        }

        $this->reservations->approve($certificate, 'Созвонились с заказчиком');
        app(CertificateManager::class)->transition($certificate->refresh(), CertificateStatus::Used);
        $this->assertSame(CertificateStatus::Used, $certificate->status);
    }

    public function test_reserve_form_shows_name_check_and_requires_reason(): void
    {
        $certificate = $this->active();
        $this->as(Role::FrontOffice);

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->mountAction('reserve')
            ->assertSchemaStateSet(['guest_name' => 'Койчиманов Нуржан'])
            ->setActionData(['booking_number' => 'R-7', 'guest_name' => 'Ivanov Ivan', 'arrival' => '2026-10-20', 'departure' => '2026-10-21'])
            ->assertMountedActionModalSee('Не совпадает ни с получателем, ни с заказчиком')
            ->callMountedAction()
            ->assertHasActionErrors(['note' => 'required']);

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->callAction('reserve', ['booking_number' => 'R-7', 'arrival' => '2026-10-20', 'departure' => '2026-10-21'])
            ->assertHasNoActionErrors();
        $this->assertSame('R-7', $certificate->refresh()->pms_booking_number);
    }

    public function test_readiness_levels_and_upcoming_page(): void
    {
        $ready = $this->reservations->save($this->active(), $this->booking());
        $action = $this->reservations->save($this->active(true), $this->booking(extra: ['booking_number' => 'R-2', 'arrival' => '2026-10-19', 'departure' => '2026-10-21']));
        $exception = $this->reservations->save($this->active(), $this->booking('Петров Иван', ['booking_number' => 'R-3', 'note' => 'друг']));

        $this->assertSame(ArrivalReadinessLevel::Ready, ArrivalReadiness::for($ready->refresh())['level']);
        $this->assertSame(ArrivalReadinessLevel::Action, ArrivalReadiness::for($action->refresh())['level']);
        $this->assertSame(['Ужин × 2: Ожидает'], ArrivalReadiness::for($action)['reasons']);
        $this->assertSame(ArrivalReadinessLevel::Exception, ArrivalReadiness::for($exception->refresh())['level']);

        $dinner = $action->fulfillments()->firstOrFail();
        app(FulfillmentManager::class)->request($dinner);
        app(FulfillmentManager::class)->confirm($dinner);
        $this->assertSame(FulfillmentStatus::Confirmed, $dinner->refresh()->status);
        $this->assertSame(ArrivalReadinessLevel::Ready, ArrivalReadiness::for($action->refresh())['level']);

        $this->as(Role::FrontOffice);
        Livewire::test(UpcomingArrivals::class)
            ->assertCanSeeTableRecords([$ready, $action, $exception])
            ->filterTable('readiness', 'exception')
            ->assertCanSeeTableRecords([$exception])->assertCanNotSeeTableRecords([$ready, $action])
            ->filterTable('readiness', 'ready')
            ->assertCanSeeTableRecords([$ready, $action])->assertCanNotSeeTableRecords([$exception]);
        Livewire::test(UpcomingArrivals::class)->filterTable('period', 'tomorrow')
            ->assertCanSeeTableRecords([$action])->assertCanNotSeeTableRecords([$ready]);
    }

    public function test_access(): void
    {
        $this->as(Role::Sales);
        $this->get('/admin/arrivals')->assertForbidden();
        $this->get('/admin/reservation-reviews')->assertForbidden();

        $this->as(Role::FrontOffice);
        $this->get('/admin/arrivals')->assertOk()->assertSee('Ближайшие заезды');
        $this->get('/admin/reservation-reviews')->assertForbidden();

        $this->as(Role::SalesManager);
        $this->get('/admin/arrivals')->assertOk();

        $this->as(Role::Finance);
        $this->get('/admin/reservation-reviews')->assertOk()->assertSee('Проверка броней');
    }
}
