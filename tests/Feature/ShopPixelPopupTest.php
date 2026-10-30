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

/** Meta Pixel (события) и всплывающее окно «Затрудняетесь?» на витрине. */
class ShopPixelPopupTest extends TestCase
{
    use RefreshDatabase;

    private const PIXEL = '123456789012345';

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-15 12:00:00');
        $service = Service::factory()->create();
        app(ServicePricing::class)->addPrice($service, '50000', Carbon::parse('2026-01-01'));
        $this->package = Package::create(['name' => 'Вечер', 'validity_value' => 3, 'validity_unit' => 'months', 'sold_online' => true]);
        $this->package->items()->create(['service_id' => $service->id, 'quantity' => 1]);
    }

    private function settings(array $values): void
    {
        ShopSetting::current()->fill($values)->save();
    }

    public function test_pixel_is_absent_without_id_and_rendered_with_noscript_when_set(): void
    {
        $this->get('/')->assertOk()->assertDontSee('fbevents.js', false);

        $this->settings(['meta_pixel_id' => self::PIXEL]);
        $this->get('/')->assertSee('fbevents.js', false)->assertSee("fbq('init', \"".self::PIXEL.'")', false)->assertSee('facebook.com/tr?id='.self::PIXEL, false);

        $this->settings(['meta_pixel_id' => 'abc123']);
        $this->get('/')->assertDontSee('fbevents.js', false);
    }

    public function test_events_lead_viewcontent_and_contact_markers(): void
    {
        $this->settings(['meta_pixel_id' => self::PIXEL, 'whatsapp_phone' => '+77011234567']);

        $this->get('/order/'.$this->package->id)->assertSee("fbq('track', 'ViewContent'", false)->assertSee('data-contact="whatsapp"', false)->assertSee("fbq('track', 'Contact')", false);
        $this->get('/')->assertDontSee("fbq('track', 'ViewContent'", false)->assertDontSee("fbq('track', 'Lead')", false);
        $this->get('/order/thanks')->assertSee("fbq('track', 'Lead')", false);
    }

    public function test_csp_adds_facebook_hosts_only_with_pixel(): void
    {
        $policy = fn () => (string) $this->get('/')->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringNotContainsString('facebook', $policy());

        $this->settings(['meta_pixel_id' => self::PIXEL]);
        $this->assertStringContainsString('https://connect.facebook.net', $policy());
        $this->assertStringContainsString('https://www.facebook.com', $policy());
    }

    public function test_privacy_mentions_meta_only_with_pixel(): void
    {
        $this->settings(['ga4_id' => 'G-ABCD1234']);
        $this->get('/privacy')->assertDontSee('Meta Pixel');

        $this->settings(['meta_pixel_id' => self::PIXEL]);
        $this->get('/privacy')->assertSee('Meta Pixel');
    }

    public function test_popup_needs_toggle_and_whatsapp_number_and_only_on_shop_and_order_pages(): void
    {
        $this->settings(['help_popup_enabled' => true]);
        $this->get('/')->assertDontSee('id="help-pop"', false);

        $this->settings(['whatsapp_phone' => '+77011234567', 'help_popup_enabled' => false]);
        $this->get('/')->assertDontSee('id="help-pop"', false);

        $this->settings(['help_popup_enabled' => true, 'help_popup_delay' => 45]);
        $this->get('/')->assertSee('id="help-pop"', false)->assertSee('delay = 45', false)->assertSee('tel:+77011234567', false)->assertSee('https://wa.me/77011234567', false);
        $this->get('/order/'.$this->package->id)->assertSee('id="help-pop"', false);
        $this->get('/order/thanks')->assertDontSee('id="help-pop"', false);
        $this->get('/faq')->assertDontSee('id="help-pop"', false);
    }

    public function test_popup_delay_is_clamped_and_texts_follow_language(): void
    {
        $this->settings(['whatsapp_phone' => '+77011234567', 'help_popup_enabled' => true, 'help_popup_delay' => 5,
            'help_popup_title' => 'Нужна помощь?', 'text_translations' => ['help_popup_title' => ['kk' => 'Көмек керек пе?', 'en' => 'Need help?']]]);

        $this->get('/?lang=ru')->assertSee('delay = 10', false)->assertSee('Нужна помощь?');
        $this->get('/?lang=kk')->assertSee('Көмек керек пе?');
        $this->get('/?lang=en')->assertSee('Need help?');
    }

    public function test_settings_are_saved_by_admin(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));

        Livewire::test(ShopSettings::class)
            ->fillForm(['meta_pixel_id' => 'oops'])->call('save')->assertHasFormErrors(['meta_pixel_id']);

        Livewire::test(ShopSettings::class)
            ->fillForm(['meta_pixel_id' => self::PIXEL, 'whatsapp_phone' => '+77011234567', 'help_popup_enabled' => true, 'help_popup_delay' => 90, 'help_popup_title' => 'Помочь?', 'help_popup_title_en' => 'Help?'])
            ->call('save')->assertHasNoFormErrors();

        $setting = ShopSetting::current();
        $this->assertSame(self::PIXEL, $setting->metaPixel());
        $this->assertTrue($setting->helpPopupEnabled());
        $this->assertSame(90, $setting->helpPopupDelay());
        $this->assertSame('Help?', $setting->text('help_popup_title', '', 'en'));
    }
}
