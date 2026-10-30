<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Service;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Мобильное меню витрины: бургер и выезжающая панель без дублирования ссылок. */
class ShopMobileMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_burger_is_wired_to_the_menu_and_links_are_not_duplicated(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');
        $service = Service::factory()->create();
        app(ServicePricing::class)->addPrice($service, '50000', Carbon::parse('2026-01-01'));
        $package = Package::create(['name' => 'Вечер', 'validity_value' => 3, 'validity_unit' => 'months', 'sold_online' => true]);
        $package->items()->create(['service_id' => $service->id, 'quantity' => 1]);

        foreach (['/', '/faq', '/order/'.$package->id] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('id="burger"', $html, $url);
            $this->assertStringContainsString('aria-controls="menu"', $html, $url);
            $this->assertStringContainsString('aria-expanded="false"', $html, $url);
            $this->assertStringContainsString('id="menu"', $html, $url);
            $this->assertSame(1, substr_count($html, 'class="menu-close"'), $url);
            $header = substr($html, (int) strpos($html, '<header'), (int) strpos($html, '</header>') - (int) strpos($html, '<header'));
            $this->assertSame(1, substr_count($header, 'Вопросы и ответы</a>'), $url);
        }
    }

    public function test_visitka_mode_has_the_burger_too_and_label_is_translated(): void
    {
        $this->get('/?lang=kk')->assertOk()->assertSee('id="burger"', false)->assertSee('aria-label="Мәзір"', false);
        $this->get('/?lang=en')->assertSee('aria-label="Menu"', false);
        $this->get('/?lang=ru')->assertSee('aria-label="Меню"', false);
    }
}
