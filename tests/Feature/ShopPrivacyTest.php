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

/** Политика конфиденциальности витрины: короткая страница со ссылками на действующие документы. */
class ShopPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-15 12:00:00');
        $service = Service::factory()->create(['name' => 'Ужин на двоих']);
        app(ServicePricing::class)->addPrice($service, '50000', Carbon::parse('2026-01-01'));
        $this->package = Package::create(['name' => 'Романтический вечер', 'validity_value' => 3, 'validity_unit' => 'months', 'sold_online' => true]);
        $this->package->items()->create(['service_id' => $service->id, 'quantity' => 1]);
    }

    public function test_page_names_the_operator_and_links_to_internal_documents_in_three_languages(): void
    {
        config(['gifter.certificate.hotel_contacts' => '+7 700 000 00 00']);

        $this->get('/privacy')->assertOk()->assertSee('Политика конфиденциальности')->assertSee('ТОО «Alpine Hotel»')->assertSee('+7 700 000 00 00')
            ->assertSee('/terms', false)->assertSee('/faq', false)->assertSee('Мы их не видим и не храним')
            ->assertDontSee('Google Analytics')->assertDontSee('Публичная оферта');
        $this->get('/privacy?lang=kk')->assertOk()->assertSee('Құпиялылық саясаты')->assertSee('Біз кімбіз');
        $this->get('/privacy?lang=en')->assertOk()->assertSee('Privacy policy')->assertSee('Who we are');
    }

    public function test_offer_link_and_operator_come_from_settings_and_only_https_is_accepted(): void
    {
        ShopSetting::create(['operator_name' => 'ТОО «Тест»', 'offer_url' => 'https://example.kz/offer.pdf']);
        $this->get('/privacy')->assertSee('ТОО «Тест»')->assertSee('href="https://example.kz/offer.pdf"', false);
        $this->get('/')->assertSee('href="https://example.kz/offer.pdf"', false);
        $this->get('/order/'.$this->package->id)->assertSee('href="https://example.kz/offer.pdf"', false)->assertSee('/privacy', false);

        foreach (['javascript:alert(1)', 'http://example.kz/offer', 'https://example.kz/a"onclick="x'] as $bad) {
            ShopSetting::current()->update(['offer_url' => $bad]);
            $this->assertNull(ShopSetting::current()->offerUrl());
            $this->get('/privacy')->assertDontSee('Публичная оферта')->assertDontSee('javascript:alert', false);
        }
    }

    public function test_policy_is_linked_from_footer_form_cookie_note_and_sitemap_and_mentions_analytics_only_when_on(): void
    {
        $this->get('/')->assertSee('/privacy', false)->assertSee('Политика конфиденциальности');
        $this->get('/order/'.$this->package->id)->assertSee('/privacy', false);
        $this->get('/sitemap.xml')->assertSee(url('/privacy'), false);
        $this->get('/robots.txt')->assertDontSee('Disallow: /privacy');

        ShopSetting::create(['ga4_id' => 'G-ABC12345']);
        $this->get('/privacy')->assertSee('Google Analytics');
        $this->get('/')->assertSee('cookie-note', false)->assertSee('Подробнее');
    }

    public function test_admin_saves_operator_and_offer_address_and_https_is_required(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        Livewire::test(ShopSettings::class)->fillForm(['offer_url' => 'http://example.kz/offer'])->call('save')->assertHasFormErrors(['offer_url']);
        Livewire::test(ShopSettings::class)->fillForm(['operator_name' => 'ТОО «Alpine Hotel»', 'offer_url' => 'https://example.kz/offer'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('https://example.kz/offer', ShopSetting::current()->offerUrl());
        $this->assertSame('ТОО «Alpine Hotel»', ShopSetting::current()->operator());
    }
}
