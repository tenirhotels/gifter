<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\ShopSettings;
use App\Models\Package;
use App\Models\Service;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Настройки сайта: способы получения, тексты на трёх языках, режим сайта-визитки без пакетов на продажу. */
class ShopVisitkaTest extends TestCase
{
    use RefreshDatabase;

    private function onlinePackage(): Package
    {
        Carbon::setTestNow('2026-05-15 12:00:00');
        $service = Service::factory()->create(['name' => 'Ужин на двоих']);
        app(ServicePricing::class)->addPrice($service, '50000', Carbon::parse('2026-01-01'));
        $package = Package::create(['name' => 'Романтический вечер', 'validity_value' => 3, 'validity_unit' => 'months', 'sold_online' => true]);
        $package->items()->create(['service_id' => $service->id, 'quantity' => 1]);

        return $package;
    }

    public function test_without_packages_site_is_a_showcase_with_contact_invitation(): void
    {
        config(['gifter.certificate.hotel_contacts' => '+7 701 000 00 00, info@example.test']);
        ShopSetting::create(['whatsapp_phone' => '+77011234567', 'contact_invite' => 'Напишите нам — подберём подарок']);

        $this->get('/')->assertOk()
            ->assertSee('Напишите нам — подберём подарок')->assertSee('info@example.test')->assertSee('wa.me/77011234567', false)
            ->assertSee('id="contact"', false)
            ->assertDontSee('Выбрать сертификат')->assertDontSee('Посмотреть сертификаты')->assertDontSee('id="gifts"', false)->assertDontSee('Оформите покупку');
        $this->get('/faq')->assertOk()->assertDontSee('#gifts', false);
    }

    public function test_with_packages_site_keeps_certificate_selection(): void
    {
        $this->onlinePackage();

        $this->get('/')->assertOk()->assertSee('Выбрать сертификат')->assertSee('id="gifts"', false)->assertDontSee('id="contact"', false);
    }

    public function test_texts_follow_visitor_language_with_russian_fallback(): void
    {
        ShopSetting::create(['hero_title' => 'Особенный подарок', 'hero_subtitle' => 'Горы и тишина',
            'text_translations' => ['hero_title' => ['kk' => 'Ерекше сыйлық', 'en' => 'A special gift']]]);

        $this->get('/?lang=kk')->assertSee('Ерекше сыйлық')->assertSee('Горы и тишина');
        $this->get('/?lang=en')->assertSee('A special gift');
        $this->get('/?lang=ru')->assertSee('Особенный подарок');
        $this->assertSame('Подарите время', ShopSetting::create(['hero_title' => null])->text('hero_intro', 'Подарите время'), 'Пусто — текст по умолчанию');
    }

    public function test_only_active_delivery_methods_are_offered_and_accepted(): void
    {
        $package = $this->onlinePackage();
        ShopSetting::create(['delivery_methods' => ['pickup', 'pdf']]);

        $this->get('/order/'.$package->id)->assertOk()->assertSee('value="pickup"', false)->assertSee('value="pdf"', false)->assertDontSee('value="courier"', false);

        $form = ['last_name' => 'Иванов', 'first_name' => 'Иван', 'phone' => '8 701 123 45 67', 'email' => 'ivan@example.com', 'consent' => '1'];
        $this->post('/order/'.$package->id, $form + ['delivery_method' => 'courier', 'delivery_address' => 'ул. Абая 1'])->assertSessionHasErrors('delivery_method');
        $this->post('/order/'.$package->id, $form + ['delivery_method' => 'pickup'])->assertSessionDoesntHaveErrors('delivery_method');
        $this->assertSame(['pickup', 'pdf'], array_map(fn ($m) => $m->value, ShopSetting::current()->deliveryMethods()));
        ShopSetting::current()->update(['delivery_methods' => ['мусор']]);
        $this->assertCount(3, ShopSetting::current()->deliveryMethods(), 'Пусто или мусор — все способы');
    }

    public function test_admin_saves_trilingual_texts_and_delivery_methods(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));

        Livewire::test(ShopSettings::class)
            ->fillForm(['hero_title' => 'Заголовок', 'hero_title_kk' => 'Тақырып', 'hero_title_en' => 'Title',
                'contact_invite' => 'Позвоните нам', 'contact_invite_en' => 'Call us', 'delivery_methods' => ['pdf', 'courier']])
            ->call('save')->assertHasNoFormErrors();

        $setting = ShopSetting::current();
        $this->assertSame('Заголовок', $setting->hero_title);
        $this->assertSame(['kk' => 'Тақырып', 'en' => 'Title'], $setting->text_translations['hero_title']);
        $this->assertSame(['en' => 'Call us'], $setting->text_translations['contact_invite']);
        $this->assertSame(['courier', 'pdf'], collect($setting->delivery_methods)->sort()->values()->all());

        Livewire::test(ShopSettings::class)->fillForm(['delivery_methods' => []])->call('save')->assertHasFormErrors(['delivery_methods']);
    }
}
