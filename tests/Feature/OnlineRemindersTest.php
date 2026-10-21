<?php

namespace Tests\Feature;

use App\Enums\HistoryEvent;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\SaleSource;
use App\Filament\Resources\OnlineOrders\Pages\ListOnlineOrders;
use App\Filament\Resources\OnlineOrders\Widgets\OnlineFunnelWidget;
use App\Mail\CertificateExpiringMail;
use App\Mail\OnlineOrdersReminderMail;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\OnlineOrder;
use App\Models\Package;
use App\Models\Service;
use App\Models\User;
use App\Services\CertificateManager;
use App\Services\OnlineFunnel;
use App\Services\OnlineOrderManager;
use App\Services\PaymentManager;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Канал «Сайт», напоминания по расписанию и воронка заявок с сайта. */
class OnlineRemindersTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-15 12:00:00');
        config(['gifter.online.mail_to' => 'sales@example.com']);
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $service = Service::factory()->create(['name' => 'Ужин на двоих']);
        app(ServicePricing::class)->addPrice($service, '50000', Carbon::parse('2026-01-01'));
        $this->package = Package::create(['name' => 'Романтический вечер', 'validity_value' => 3, 'validity_unit' => 'months', 'sold_online' => true]);
        $this->package->items()->create(['service_id' => $service->id, 'quantity' => 1]);
    }

    private function order(array $override = []): OnlineOrder
    {
        return OnlineOrder::create(array_merge(['package_id' => $this->package->id, 'last_name' => 'Иванов', 'first_name' => 'Иван',
            'phone' => '+77011234567', 'email' => 'ivan@example.com'], $override));
    }

    private function issue(Certificate $certificate): Certificate
    {
        $payments = app(PaymentManager::class);
        $payments->markPaid($payments->invoice($certificate, PaymentMethod::KaspiInvoice));

        return app(CertificateManager::class)->issue($certificate)->refresh();
    }

    public function test_certificate_from_website_order_gets_website_channel_and_link(): void
    {
        $order = $this->order();
        $certificate = app(OnlineOrderManager::class)->process($order, User::factory()->create(['roles' => [Role::Sales]]));

        $this->assertSame(SaleSource::Website, $certificate->source);
        $this->assertSame($order->id, $certificate->online_order_id);

        $staff = app(CertificateManager::class)->createDraft(['package_id' => $this->package->id, 'customer_id' => Customer::factory()->create()->id]);
        $this->assertSame(SaleSource::Staff, $staff->refresh()->source);
    }

    public function test_staff_is_reminded_once_about_orders_waiting_over_two_hours_without_client_contacts(): void
    {
        Mail::fake();
        $old = $this->order();
        $old->forceFill(['created_at' => now()->subHours(3)])->save();
        $this->order();

        $this->artisan('gifter:remind-online-orders')->assertSuccessful();
        Mail::assertQueued(OnlineOrdersReminderMail::class, function (OnlineOrdersReminderMail $mail) use ($old) {
            $html = $mail->render();

            return array_keys($mail->orders) === [$old->id] && $mail->hasTo('sales@example.com')
                && str_contains($html, 'Романтический вечер') && ! str_contains($html, 'ivan@example.com') && ! str_contains($html, '7011234567');
        });
        $this->assertNotNull($old->refresh()->reminded_at);

        $this->artisan('gifter:remind-online-orders')->assertSuccessful();
        Mail::assertQueuedCount(1);
    }

    public function test_guest_is_reminded_14_days_before_expiry_once_and_only_with_email(): void
    {
        Mail::fake();
        $manager = app(CertificateManager::class);
        $withEmail = $this->issue($manager->createDraft(['package_id' => $this->package->id,
            'customer_id' => Customer::factory()->create(['email' => 'guest@example.com'])->id]));
        $noEmail = $this->issue($manager->createDraft(['package_id' => $this->package->id,
            'customer_id' => Customer::factory()->create(['email' => null])->id]));
        $later = $this->issue($manager->createDraft(['package_id' => $this->package->id,
            'customer_id' => Customer::factory()->create(['email' => 'later@example.com'])->id]));
        $withEmail->forceFill(['valid_until' => today()->addDays(14)])->save();
        $noEmail->forceFill(['valid_until' => today()->addDays(10)])->save();
        $later->forceFill(['valid_until' => today()->addDays(30)])->save();

        $this->artisan('gifter:remind-expiring')->assertSuccessful();
        Mail::assertQueued(CertificateExpiringMail::class, fn (CertificateExpiringMail $mail) => $mail->hasTo('guest@example.com')
            && $mail->number === $withEmail->number && str_contains($mail->render(), '/v/'.$withEmail->verification_token));
        Mail::assertQueuedCount(1);
        $this->assertNotNull($withEmail->refresh()->expiry_reminded_at);
        $this->assertTrue($withEmail->history()->where('event', HistoryEvent::ExpiryReminded)->exists());
        $this->assertNull($noEmail->refresh()->expiry_reminded_at);

        $this->artisan('gifter:remind-expiring')->assertSuccessful();
        Mail::assertQueuedCount(1);
    }

    public function test_funnel_counts_orders_processed_paid_issued_and_sum_for_period(): void
    {
        $seller = User::factory()->create(['roles' => [Role::Sales]]);
        $orders = app(OnlineOrderManager::class);
        $this->issue($orders->process($this->order(), $seller));
        $orders->process($this->order(), $seller);
        $this->order();
        $lastMonth = $this->order();
        $lastMonth->forceFill(['created_at' => now()->subMonth()])->save();

        $funnel = app(OnlineFunnel::class)->build('this');
        $this->assertSame([3, 2, 1, 1, 0], array_column($funnel['steps'], 'count'));
        $this->assertSame(100, $funnel['steps'][0]['percent']);
        $this->assertSame(67, $funnel['steps'][1]['percent']);
        $this->assertStringContainsString('50 000', $funnel['amount']);
        $this->assertSame([1, 0, 0, 0, 0], array_column(app(OnlineFunnel::class)->build('last')['steps'], 'count'));

        Livewire::test(OnlineFunnelWidget::class)->assertSee('Воронка продаж с сайта')->set('period', 'last')->assertOk();
        Livewire::test(ListOnlineOrders::class)->assertOk()->assertSeeLivewire(OnlineFunnelWidget::class);
    }
}
