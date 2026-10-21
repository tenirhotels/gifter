<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\ShopSettings;
use App\Mail\OnlineOrderReceivedMail;
use App\Models\Package;
use App\Models\Service;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** SEO витрины, превью ссылок, аналитика и кнопка WhatsApp. */
class ShopSeoTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-15 12:00:00');
        $service = Service::factory()->create(['name' => 'Ужин на двоих']);
        app(ServicePricing::class)->addPrice($service, '50000', Carbon::parse('2026-01-01'));
        $this->package = Package::create(['name' => 'Романтический вечер', 'description' => 'Ужин при свечах', 'validity_value' => 3, 'validity_unit' => 'months', 'sold_online' => true]);
        $this->package->items()->create(['service_id' => $service->id, 'quantity' => 1]);
        Package::create(['name' => 'Скрытый', 'validity_value' => 3, 'validity_unit' => 'months', 'sold_online' => false]);
    }

    public function test_pages_have_canonical_hreflang_open_graph_and_product_json_ld(): void
    {
        $html = $this->get('/order/'.$this->package->id)->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.url('/order/'.$this->package->id).'">', $html);
        foreach (['kk', 'ru', 'en'] as $code) {
            $this->assertStringContainsString('hreflang="'.$code.'" href="'.url('/order/'.$this->package->id).'?lang='.$code.'"', $html);
        }
        $this->assertStringContainsString('hreflang="x-default"', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Романтический вечер', $html);
        $this->assertStringContainsString('<meta property="og:description" content="Ужин при свечах">', $html);
        $this->assertStringContainsString('<meta name="twitter:card"', $html);

        preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $m);
        $data = json_decode($m[1], true);
        $this->assertSame('Product', $data['@type']);
        $this->assertSame('Романтический вечер', $data['name']);
        $this->assertSame('KZT', $data['offers']['priceCurrency']);
        $this->assertSame('50000.00', number_format((float) $data['offers']['price'], 2, '.', ''));
    }

    public function test_thanks_page_is_noindex_and_robots_and_sitemap_are_served(): void
    {
        $this->get('/order/thanks')->assertOk()->assertSee('noindex, nofollow', false);

        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Disallow: /admin', $robots);
        $this->assertStringContainsString('Sitemap: '.url('/sitemap.xml'), $robots);

        $sitemap = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString('<loc>'.url('/order/'.$this->package->id).'</loc>', $sitemap);
        $this->assertStringContainsString('<loc>'.url('/faq').'</loc>', $sitemap);
        $this->assertStringNotContainsString('/order/thanks', $sitemap);
        $this->assertSame(1, substr_count($sitemap, '<loc>'.url('/order/')));
        $this->assertNotFalse(simplexml_load_string($sitemap));
    }

    public function test_nothing_is_connected_until_ids_are_set(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        foreach (['googletagmanager', 'mc.yandex.ru', 'wa.me', 'cookie-note'] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }

    public function test_analytics_whatsapp_cookie_note_and_goal_appear_when_configured(): void
    {
        ShopSetting::create(['ga4_id' => 'G-ABC12345', 'metrika_id' => '1234567', 'whatsapp_phone' => '8 701 123 45 67']);

        $this->get('/')->assertOk()->assertSee('googletagmanager.com/gtag/js?id=G-ABC12345', false)->assertSee("ym(1234567, 'init'", false)
            ->assertSee('https://wa.me/77011234567?text=', false)->assertSee('cookie-note', false);
        $this->get('/order/'.$this->package->id)->assertSee(rawurlencode('Романтический вечер'), false);
        $this->get('/order/thanks')->assertSee("gtag('event', 'order_submitted')", false)->assertSee("reachGoal', 'order_submitted'", false);
    }

    public function test_invalid_ids_are_never_printed_into_the_page(): void
    {
        ShopSetting::create(['ga4_id' => "G-1');alert(1);//", 'metrika_id' => '12ab', 'whatsapp_phone' => 'abc']);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('googletagmanager', $html);
        $this->assertStringNotContainsString('mc.yandex.ru', $html);
        $this->assertStringNotContainsString('wa.me', $html);
    }

    public function test_confirmation_mail_has_whatsapp_link_and_settings_are_saved_by_admin(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        Livewire::test(ShopSettings::class)
            ->fillForm(['ga4_id' => 'bad', 'metrika_id' => '123'])->call('save')->assertHasFormErrors(['ga4_id', 'metrika_id']);
        Livewire::test(ShopSettings::class)
            ->fillForm(['ga4_id' => 'G-ABC12345', 'metrika_id' => '1234567', 'whatsapp_phone' => '8 701 123 45 67'])->call('save')->assertHasNoFormErrors();

        $setting = ShopSetting::current();
        $this->assertSame('G-ABC12345', $setting->ga4());
        $this->assertSame('+77011234567', $setting->whatsapp_phone);
        $this->assertStringContainsString('https://wa.me/77011234567', (new OnlineOrderReceivedMail('Пакет', 'ru'))->render());
    }

    public function test_brand_font_is_linked_only_when_file_exists(): void
    {
        $file = public_path('fonts/montserrat-latin.woff2');
        $this->assertFileDoesNotExist($file);
        $this->get('/')->assertOk()->assertDontSee('@font-face', false);

        file_put_contents($file, 'wOF2');
        try {
            $this->get('/')->assertOk()->assertSee("url('/fonts/montserrat-latin.woff2')", false);
        } finally {
            unlink($file);
        }
    }
}
