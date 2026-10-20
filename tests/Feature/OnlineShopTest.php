<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\OnlineOrderStatus;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\OnlineOrders\Pages\ListOnlineOrders;
use App\Mail\OnlineOrderMail;
use App\Mail\OnlineOrderReceivedMail;
use App\Models\Customer;
use App\Models\OnlineOrder;
use App\Models\Package;
use App\Models\Service;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\OnlineOrderManager;
use App\Services\ServicePricing;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Публичная страница «/»: витрина онлайн-пакетов, заявка и её оформление сотрудником. */
class OnlineShopTest extends TestCase
{
    use RefreshDatabase;

    private Package $online;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-15 12:00:00');
        $service = Service::factory()->create(['name' => 'Ужин на двоих']);
        app(ServicePricing::class)->addPrice($service, '50000', Carbon::parse('2026-01-01'));
        $this->online = $this->package('Романтический вечер', $service, online: true);
    }

    private function package(string $name, Service $service, bool $online = false, bool $free = false): Package
    {
        $package = Package::create(['name' => $name, 'description' => 'Описание '.$name, 'validity_value' => 3, 'validity_unit' => 'months',
            'sold_online' => $online, 'is_complimentary' => $free, 'complimentary_purpose' => $free ? 'partners' : null]);
        $package->items()->create(['service_id' => $service->id, 'quantity' => 1]);

        return $package;
    }

    /** @return array<string, mixed> */
    private function form(array $override = []): array
    {
        return array_merge(['last_name' => 'Иванов', 'first_name' => 'Иван', 'phone' => '8 701 123 45 67', 'email' => 'ivan@example.com',
            'recipient_name' => 'Петрова Анна', 'gift_message' => 'С днём рождения!', 'delivery_method' => 'pickup', 'notes' => 'К 8 марта', 'consent' => '1'], $override);
    }

    public function test_root_is_public_shop_and_shows_only_paid_online_packages_with_price(): void
    {
        $service = Service::factory()->create(['name' => 'Без цены']);
        $this->package('Не онлайн', Service::factory()->create(), online: false);
        $this->package('Бесплатный', Service::factory()->create(), online: false, free: true);
        $this->package('Нет цены', $service, online: true);

        $this->get('/')->assertOk()->assertSee('Романтический вечер')->assertSee('50 000')
            ->assertDontSee('Не онлайн')->assertDontSee('Бесплатный')->assertDontSee('Нет цены')->assertSee('/admin');
        $this->get('/admin')->assertRedirect();
        $this->get('/?lang=kk')->assertOk()->assertSee('Сертификаттар');
        $this->get('/order/'.$this->online->id)->assertOk()->assertSee('Ужин на двоих')->assertSee('currentColor', false);
        $this->get('/order/'.Package::where('name', 'Не онлайн')->value('id'))->assertNotFound();
    }

    public function test_order_is_saved_with_encrypted_contacts_and_staff_is_notified_without_contacts(): void
    {
        Mail::fake();
        config(['gifter.online.mail_to' => 'orders@example.com']);

        $this->get('/order/'.$this->online->id)->assertOk()->assertSee('Романтический вечер');
        $this->post('/order/'.$this->online->id, $this->form())->assertRedirect('/order/thanks');

        $order = OnlineOrder::firstOrFail();
        $this->assertSame('+77011234567', $order->phone);
        $this->assertSame(OnlineOrderStatus::New, $order->status);
        $this->assertNotSame('+77011234567', DB::table('online_orders')->value('phone'));
        Mail::assertSent(OnlineOrderMail::class, fn (OnlineOrderMail $m) => $m->hasTo('orders@example.com')
            && ! str_contains($m->render(), '701') && ! str_contains($m->render(), 'ivan@example.com'));
        Mail::assertSent(OnlineOrderReceivedMail::class, fn ($m) => $m->hasTo('ivan@example.com'));
        $this->get('/order/thanks')->assertOk()->assertSee('Заявка принята');
    }

    public function test_validation_and_honeypot(): void
    {
        $this->post('/order/'.$this->online->id, $this->form(['phone' => '12']))->assertSessionHasErrors('phone');
        $this->post('/order/'.$this->online->id, $this->form(['consent' => null]))->assertSessionHasErrors('consent');
        $this->post('/order/'.$this->online->id, $this->form(['website' => 'http://spam']))->assertRedirect('/order/thanks');
        $this->assertSame(0, OnlineOrder::count());
    }

    public function test_staff_processes_order_into_draft_with_seller_and_reuses_customer(): void
    {
        $this->post('/order/'.$this->online->id, $this->form());
        $this->post('/order/'.$this->online->id, $this->form(['recipient_name' => '']));
        [$first, $second] = OnlineOrder::orderBy('id')->get()->all();
        $seller = User::factory()->create(['roles' => [Role::Sales]]);
        $manager = app(OnlineOrderManager::class);

        $certificate = $manager->process($first, $seller);
        $this->assertSame(CertificateStatus::Draft, $certificate->status);
        $this->assertSame($seller->id, $certificate->sold_by);
        $this->assertSame($this->online->id, $certificate->package_id);
        $this->assertSame('Петрова Анна', $certificate->recipient->full_name);
        $this->assertSame(OnlineOrderStatus::Processed, $first->refresh()->status);

        $manager->process($second, $seller);
        $this->assertSame(1, Customer::count());

        $this->expectException(DomainRuleViolation::class);
        $manager->process($first, $seller);
    }

    public function test_reject_needs_reason_and_page_access_by_role(): void
    {
        $this->post('/order/'.$this->online->id, $this->form());
        $order = OnlineOrder::firstOrFail();
        $seller = User::factory()->create(['roles' => [Role::Sales]]);

        $this->actingAs(User::factory()->create(['roles' => [Role::Finance]]));
        $this->get('/admin/online-orders')->assertForbidden();

        $this->actingAs($seller);
        $this->get('/admin/online-orders')->assertOk()->assertSee('Романтический вечер');
        Livewire::test(ListOnlineOrders::class)->assertCanSeeTableRecords([$order])
            ->callAction(TestAction::make('process')->table($order))->assertNotified();
        $this->assertSame(OnlineOrderStatus::Processed, $order->refresh()->status);

        $this->post('/order/'.$this->online->id, $this->form());
        $second = OnlineOrder::where('status', 'new')->firstOrFail();
        $this->expectException(DomainRuleViolation::class);
        app(OnlineOrderManager::class)->reject($second, $seller, 'нет');
    }

    public function test_delivery_methods_validation_and_gift_message_reach_the_draft(): void
    {
        $this->post('/order/'.$this->online->id, $this->form(['delivery_method' => 'courier', 'delivery_address' => '']))->assertSessionHasErrors('delivery_address');
        $this->post('/order/'.$this->online->id, $this->form(['delivery_method' => 'pdf', 'email' => '']))->assertSessionHasErrors('email');
        $this->post('/order/'.$this->online->id, $this->form(['delivery_method' => 'post']))->assertSessionHasErrors('delivery_method');

        $this->post('/order/'.$this->online->id, $this->form(['delivery_method' => 'courier', 'delivery_address' => 'Алматы, Абая 10, кв. 5']))->assertRedirect('/order/thanks');
        $order = OnlineOrder::firstOrFail();
        $this->assertSame('Алматы, Абая 10, кв. 5', $order->delivery_address);
        $this->assertNotSame('Алматы, Абая 10, кв. 5', DB::table('online_orders')->value('delivery_address'));

        $certificate = app(OnlineOrderManager::class)->process($order, User::factory()->create(['roles' => [Role::Sales]]));
        $this->assertStringContainsString('Курьер по городу', $certificate->notes);
        $this->assertStringNotContainsString('Абая', $certificate->notes);
        $this->assertStringContainsString('С днём рождения!', $certificate->notes);
    }

    public function test_shop_media_serves_only_shop_images_and_settings_page_is_admin_only(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('shop/hero.jpg', 'jpgdata');
        Storage::disk('public')->put('secret.jpg', 'x');
        $this->get('/media/hero.jpg')->assertOk();
        $this->get('/media/secret.jpg')->assertNotFound();
        $this->get('/media/..%2Fsecret.jpg')->assertNotFound();
        $this->get('/media/notes.txt')->assertNotFound();

        $this->online->update(['image_path' => 'hero.jpg']);
        ShopSetting::create(['hero_title' => 'Особенный подарок', 'accent_color' => '#336699']);
        $this->get('/')->assertSee('Особенный подарок')->assertSee('--accent: #336699', false)->assertSee('/media/hero.jpg', false);

        $this->actingAs(User::factory()->create(['roles' => [Role::Sales]]));
        $this->get('/admin/shop-settings')->assertForbidden();
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $this->get('/admin/shop-settings')->assertOk();
    }
}
