<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Filament\Resources\Certificates\RelationManagers\FulfillmentsRelationManager;
use App\Filament\Resources\Fulfillments\Pages\ListServiceFulfillments;
use App\Filament\Support\FulfillmentActions;
use App\Filament\Widgets\FrontOfficeQueueWidget;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Provider;
use App\Models\Recipient;
use App\Models\Service;
use App\Models\ServiceFulfillment;
use App\Models\User;
use App\Services\CertificateManager;
use App\Services\FulfillmentManager;
use App\Services\PaymentManager;
use App\Services\RefundManager;
use App\Services\ReservationManager;
use App\Services\ServicePricing;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Фаза 5: заявки на исполнение услуг. */
class FulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $pricing = app(ServicePricing::class);
        $restaurant = Provider::create(['name' => 'Ресторан', 'type' => 'food_beverage', 'phone' => '+7 700 000 00 00', 'notes' => 'за сутки']);
        $breakfast = Service::factory()->create(['name' => 'Завтрак']);
        $dinner = Service::factory()->create(['name' => 'Ужин', 'provider_id' => $restaurant->id,
            'requires_fulfillment' => true, 'requires_scheduling' => true, 'requires_confirmation' => true]);
        $tickets = Service::factory()->create(['name' => 'Канатка', 'requires_fulfillment' => true]);
        foreach ([$breakfast, $dinner, $tickets] as $service) {
            $pricing->addPrice($service, '10000', Carbon::parse('2026-01-01'));
        }
        $this->package = Package::create(['name' => 'Уикенд', 'validity_value' => 6, 'validity_unit' => 'months']);
        foreach ([$breakfast, $dinner, $tickets] as $service) {
            $this->package->items()->create(['service_id' => $service->id, 'quantity' => 2]);
        }
    }

    private function as(Role ...$roles): User
    {
        $user = User::factory()->create(['roles' => $roles]);
        $this->actingAs($user);

        return $user;
    }

    private function active(): Certificate
    {
        $certificates = app(CertificateManager::class);
        $certificate = $certificates->createDraft([
            'package_id' => $this->package->id,
            'customer_id' => Customer::factory()->create()->id,
            'recipient_id' => Recipient::factory()->create(['last_name' => 'Иванов', 'first_name' => 'Иван'])->id,
        ]);
        $payments = app(PaymentManager::class);
        $payments->markPaid($payments->invoice($certificate, PaymentMethod::KaspiInvoice));

        return $certificates->issue($certificate->refresh());
    }

    private function reserved(): Certificate
    {
        return $this->reserve($this->active());
    }

    private function dinner(Certificate $certificate): ServiceFulfillment
    {
        return $certificate->fulfillments()->whereHas('item', fn ($q) => $q->where('service_name_snapshot', 'Ужин'))->latest('id')->firstOrFail();
    }

    public function test_reservation_creates_requests_only_for_services_that_need_preparation(): void
    {
        $certificate = $this->active();
        $this->assertSame(0, ServiceFulfillment::count(), 'До брони заявок нет');

        $this->reserve($certificate);

        $this->assertSame(['Ужин', 'Канатка'], $certificate->fulfillments()->with('item')->orderBy('id')->get()
            ->map(fn ($f) => $f->item->service_name_snapshot)->all());
        $dinner = $this->dinner($certificate);
        $this->assertSame(FulfillmentStatus::Pending, $dinner->status);
        $this->assertTrue($dinner->requires_confirmation);
        $this->assertSame('Ресторан', $dinner->provider->name);
        $this->assertTrue($certificate->history()->where('description', 'like', 'Ужин × 2: заявка создана')->exists());
    }

    public function test_full_path_request_schedule_confirm_complete(): void
    {
        $certificate = $this->reserved();
        $manager = app(FulfillmentManager::class);
        $dinner = $this->dinner($certificate);

        $manager->request($dinner, 'Столик на двоих');
        $this->assertSame(FulfillmentStatus::Requested, $dinner->refresh()->status);

        try {
            $manager->confirm($dinner);
            $this->fail('Подтверждение без времени у услуги с расписанием');
        } catch (DomainRuleViolation) {
        }

        $manager->schedule($dinner, Carbon::parse('2026-10-20'), '19:00');
        $this->assertSame(['scheduled', '20.10.2026 19:00'], [$dinner->refresh()->status->value, $dinner->whenLabel()]);
        $manager->confirm($dinner, 'Подтвердил администратор ресторана');
        $this->assertSame(FulfillmentStatus::Confirmed, $dinner->refresh()->status);

        try {
            $manager->complete($dinner);
            $this->fail('Выполнение до заезда');
        } catch (DomainRuleViolation) {
        }

        app(CertificateManager::class)->transition($certificate, CertificateStatus::InUse);
        $manager->complete($dinner);
        $this->assertSame(FulfillmentStatus::Completed, $dinner->refresh()->status);
        $this->assertSame(CertificateStatus::InUse, $certificate->refresh()->status, 'Выполнение услуги не гасит сертификат');
    }

    public function test_rules_on_time_validity_and_reschedule(): void
    {
        $certificate = $this->reserved();
        $manager = app(FulfillmentManager::class);
        $dinner = $this->dinner($certificate);

        foreach ([fn () => $manager->schedule($dinner, Carbon::parse('2026-10-20'), '25:00'),
            fn () => $manager->schedule($dinner, Carbon::parse('2027-12-01'), '19:00'),
            fn () => $manager->revert($dinner, 'нечего отменять — заявка ещё не отправлена')] as $i => $bad) {
            try {
                $bad();
                $this->fail("Правило {$i}");
            } catch (DomainRuleViolation) {
            }
        }

        $manager->schedule($dinner, Carbon::parse('2026-10-20'), '19:00');
        $manager->confirm($dinner);
        $manager->schedule($dinner, Carbon::parse('2026-10-21'), '20:00');
        $this->assertSame(FulfillmentStatus::Scheduled, $dinner->refresh()->status, 'Перенос снимает подтверждение');
        $this->assertNull($dinner->confirmed_at);
    }

    public function test_service_without_confirmation_can_be_completed_after_request(): void
    {
        $certificate = $this->reserved();
        app(CertificateManager::class)->transition($certificate, CertificateStatus::InUse);
        $tickets = $certificate->fulfillments()->whereHas('item', fn ($q) => $q->where('service_name_snapshot', 'Канатка'))->firstOrFail();

        app(FulfillmentManager::class)->request($tickets);
        app(FulfillmentManager::class)->complete($tickets, 'Билеты выданы на ресепшене');

        $this->assertSame(FulfillmentStatus::Completed, $tickets->refresh()->status);
    }

    public function test_release_cancels_open_requests_and_new_reservation_opens_again(): void
    {
        $certificate = $this->reserved();
        $certificates = app(CertificateManager::class);

        app(ReservationManager::class)->release($certificate, 'Гость перенёс');
        $this->assertSame(0, $certificate->fulfillments()->whereIn('status', FulfillmentStatus::open())->count());
        $this->assertSame('Бронь снята', $this->dinner($certificate)->cancellation_reason);

        $this->reserve($certificate->refresh());
        $this->assertSame(2, $certificate->fulfillments()->where('status', FulfillmentStatus::Pending)->count());
        $this->assertSame(4, $certificate->fulfillments()->count(), 'Старые заявки не удаляются');
    }

    public function test_redeem_with_open_requests_warns_and_closes_them(): void
    {
        $certificate = $this->reserved();
        app(CertificateManager::class)->transition($certificate, CertificateStatus::InUse);

        $this->as(Role::FrontOffice);
        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->mountAction('redeem')
            ->assertMountedActionModalSee('не выполнены Ужин × 2 (Ожидает), Канатка × 2 (Ожидает)')
            ->callMountedAction();

        $this->assertSame(CertificateStatus::Used, $certificate->refresh()->status);
        $this->assertSame(2, $certificate->fulfillments()->where('status', FulfillmentStatus::Cancelled)
            ->where('cancellation_reason', 'Сертификат использован, услуга не выполнена')->count());
    }

    public function test_refund_cancels_open_requests(): void
    {
        $certificate = $this->reserved();
        $refunds = app(RefundManager::class);
        $refunds->approve($refunds->request($certificate, '0', 'Гость отказался'));

        $this->assertSame(0, $certificate->fulfillments()->whereIn('status', FulfillmentStatus::open())->count());
    }

    public function test_front_office_works_queue_and_sales_cannot(): void
    {
        $certificate = $this->reserved();
        $dinner = $this->dinner($certificate);

        $this->as(Role::FrontOffice);
        $this->get('/admin/fulfillments')->assertOk()->assertSee('Исполнение услуг');
        Livewire::test(ListServiceFulfillments::class)
            ->assertCanSeeTableRecords([$dinner])
            ->mountAction(TestAction::make('requestFulfillment')->table($dinner))
            ->assertSchemaStateSet(['request_details' => "Заявка от TENIR\nУслуга: Ужин × 2\nГости: Иванов Иван\nПроживание: заезд 18.10.2026, выезд 20.10.2026\nКогда: уточним"])
            ->callMountedAction();
        $this->assertSame(FulfillmentStatus::Requested, $dinner->refresh()->status);

        Livewire::test(ListServiceFulfillments::class)
            ->assertActionHidden(TestAction::make('confirmFulfillment')->table($dinner))
            ->callAction(TestAction::make('scheduleFulfillment')->table($dinner), ['date' => '2026-10-20', 'time' => '19:00']);
        Livewire::test(ListServiceFulfillments::class)
            ->assertActionHidden(TestAction::make('scheduleFulfillment')->table($dinner))
            ->assertActionVisible(TestAction::make('rescheduleFulfillment')->table($dinner))
            ->callAction(TestAction::make('confirmFulfillment')->table($dinner), ['note' => 'ок']);
        $this->assertSame(FulfillmentStatus::Confirmed, $dinner->refresh()->status);

        Livewire::test(FrontOfficeQueueWidget::class)->assertSee('Заявки ждут отправки')->assertSee('Ждут подтверждения');

        $this->as(Role::SalesManager);
        $this->get('/admin/fulfillments')->assertOk();

        $this->as(Role::Sales);
        $this->get('/admin/fulfillments')->assertForbidden();
        Livewire::test(FulfillmentsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->assertActionHidden(TestAction::make('revertFulfillment')->table($dinner));
    }

    public function test_certificate_tab_shows_requests(): void
    {
        $certificate = $this->reserved();

        Livewire::test(FulfillmentsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->assertSee('Ужин × 2')->assertSee('нужно: время, подтверждение')->assertSee('Ресторан')->assertSee('Ожидает')
            ->assertActionHidden(TestAction::make('revertFulfillment')->table($this->dinner($certificate)));
    }

    public function test_revert_steps_back_and_keeps_the_service(): void
    {
        $certificate = $this->reserved();
        $manager = app(FulfillmentManager::class);
        $dinner = $this->dinner($certificate);
        $manager->request($dinner, 'текст', 'Иванов Иван', 'Ресторан, 2 этаж');
        $manager->schedule($dinner, Carbon::parse('2026-10-20'), '19:00');
        $manager->confirm($dinner);

        Livewire::test(FulfillmentsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->assertActionHasLabel(TestAction::make('revertFulfillment')->table($dinner), 'Снять подтверждение')
            ->callAction(TestAction::make('revertFulfillment')->table($dinner), ['reason' => 'Ресторан перенёс бронь']);

        $dinner->refresh();
        $this->assertSame([FulfillmentStatus::Scheduled, '20.10.2026 19:00'], [$dinner->status, $dinner->whenLabel()]);
        $this->assertNull($dinner->confirmed_at);
        $this->assertStringContainsString('Ресторан перенёс бронь', $dinner->notes);

        $manager->revert($dinner, 'Ресторан закрыт, ищем другой');
        $dinner->refresh();
        $this->assertSame(FulfillmentStatus::Pending, $dinner->status);
        $this->assertNull($dinner->scheduled_date);
        $this->assertSame(['Иванов Иван', 'Ресторан, 2 этаж'], [$dinner->guests, $dinner->location]);
        $this->assertSame(1, $certificate->fulfillments()->where('certificate_item_id', $dinner->certificate_item_id)->count(), 'Услуга остаётся, новая заявка не создаётся');
        $this->assertSame(0, $certificate->fulfillments()->where('status', FulfillmentStatus::Cancelled)->count());

        $this->expectException(DomainRuleViolation::class);
        $manager->revert($dinner, 'уже ожидает');
    }

    public function test_request_text_for_provider_has_no_certificate_details(): void
    {
        $certificate = $this->reserved();
        $certificate->update(['booking_guest_name' => 'Baimukhanov Akhan', 'arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22']);
        $dinner = $this->dinner($certificate->refresh());

        $text = FulfillmentActions::requestTemplate($dinner, FulfillmentActions::defaultGuests($dinner), 'Аэропорт, рейс KC 852');

        $this->assertStringContainsString('Гости: Baimukhanov Akhan', $text);
        $this->assertStringContainsString('заезд 20.10.2026, выезд 22.10.2026', $text);
        $this->assertStringContainsString('Место: Аэропорт, рейс KC 852', $text);
        $this->assertStringNotContainsString($certificate->number, $text);
        $this->assertStringNotContainsString('действует до', $text);

        Livewire::test(FulfillmentsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->mountAction(TestAction::make('requestFulfillment')->table($dinner))
            ->assertSchemaStateSet(['guests' => 'Baimukhanov Akhan'], 'mountedActionSchema0')
            ->setActionData(['location' => 'Аэропорт'])
            ->callMountedAction();

        $this->assertSame(['Baimukhanov Akhan', 'Аэропорт', FulfillmentStatus::Requested], [$dinner->refresh()->guests, $dinner->location, $dinner->status]);
    }

    public function test_fulfillments_cannot_be_deleted(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->dinner($this->reserved())->delete();
    }
}
