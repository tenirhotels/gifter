<?php

namespace Tests\Feature;

use App\Models\OnlineOrder;
use App\Models\Package;
use App\Models\Service;
use App\Models\ShopSetting;
use App\Services\OnlineFunnel;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Отслеживание конверсии: событие «заказ отправлен» один раз, источник заказа, воронка по источникам, свои визиты. */
class ShopTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-15 12:00:00');
        $service = Service::factory()->create();
        app(ServicePricing::class)->addPrice($service, '50000', Carbon::parse('2026-01-01'));
        $this->package = Package::create(['name' => 'Вечер', 'validity_value' => 3, 'validity_unit' => 'months', 'sold_online' => true]);
        $this->package->items()->create(['service_id' => $service->id, 'quantity' => 1]);
        ShopSetting::current()->fill(['ga4_id' => 'G-ABCD1234', 'metrika_id' => '12345678', 'meta_pixel_id' => '123456789012345'])->save();
    }

    /** @return array<string, mixed> */
    private function form(array $override = []): array
    {
        return array_merge(['last_name' => 'Иванов', 'first_name' => 'Иван', 'phone' => '8 701 123 45 67', 'email' => 'ivan@example.com', 'delivery_method' => 'pickup', 'consent' => '1'], $override);
    }

    public function test_conversion_fires_once_after_a_real_order_and_not_on_reload_or_direct_visit(): void
    {
        $this->get('/order/thanks')->assertDontSee("gtag('event', 'order_submitted')", false)->assertDontSee("fbq('track', 'Lead')", false);

        $response = $this->post('/order/'.$this->package->id, $this->form());
        $response->assertRedirect('/order/thanks');

        $first = $this->get('/order/thanks');
        $first->assertSee("gtag('event', 'order_submitted')", false)->assertSee("fbq('track', 'Lead')", false)->assertSee("'reachGoal', 'order_submitted'", false);

        $this->get('/order/thanks')->assertDontSee("gtag('event', 'order_submitted')", false)->assertDontSee("fbq('track', 'Lead')", false);
    }

    public function test_bot_trap_does_not_count_as_conversion(): void
    {
        $this->post('/order/'.$this->package->id, $this->form(['website' => 'http://spam.example']))->assertRedirect('/order/thanks');

        $this->get('/order/thanks')->assertDontSee("gtag('event', 'order_submitted')", false);
        $this->assertSame(0, OnlineOrder::query()->count());
    }

    public function test_source_is_saved_sanitized_and_referrer_keeps_only_the_domain(): void
    {
        $this->post('/order/'.$this->package->id, $this->form([
            'utm_source' => 'Instagram<script>', 'utm_medium' => 'cpc', 'utm_campaign' => 'NY 2026',
            'referrer' => 'https://l.instagram.com/path?token=secret',
        ]))->assertRedirect();

        $order = OnlineOrder::query()->firstOrFail();
        $this->assertSame('instagramscript', $order->utm_source);
        $this->assertSame('cpc', $order->utm_medium);
        $this->assertSame('ny 2026', $order->utm_campaign);
        $this->assertSame('l.instagram.com', $order->referrer_host);
        $this->assertSame('instagramscript', $order->sourceLabel());
    }

    public function test_own_site_is_not_a_source_and_direct_visits_are_labelled(): void
    {
        $this->post('/order/'.$this->package->id, $this->form(['referrer' => 'http://localhost/order/1']))->assertRedirect();

        $order = OnlineOrder::query()->firstOrFail();
        $this->assertNull($order->referrer_host);
        $this->assertSame('Прямой заход или не определён', $order->sourceLabel());
    }

    public function test_order_page_has_attribution_fields_view_item_and_form_start(): void
    {
        $this->get('/order/'.$this->package->id)->assertOk()
            ->assertSee('name="utm_source"', false)->assertSee('name="referrer"', false)
            ->assertSee("gtag('event', 'view_item'", false)->assertSee("gtag('event', 'form_start')", false)->assertSee("'reachGoal', 'form_start'", false);
    }

    public function test_internal_visits_switch_is_in_the_head_only_with_counters(): void
    {
        $this->get('/')->assertSee('gifter_notrack', false)->assertSee('window.gifterNoTrack', false);

        ShopSetting::current()->fill(['ga4_id' => null, 'metrika_id' => null, 'meta_pixel_id' => null])->save();
        $this->get('/')->assertDontSee('gifter_notrack', false);
    }

    public function test_funnel_groups_orders_by_source(): void
    {
        $base = ['package_id' => $this->package->id, 'last_name' => 'А', 'first_name' => 'Б', 'phone' => '+77010000001'];
        OnlineOrder::create($base + ['utm_source' => 'instagram']);
        OnlineOrder::create($base + ['phone' => '+77010000002', 'utm_source' => 'instagram']);
        OnlineOrder::create($base + ['phone' => '+77010000003', 'referrer_host' => 'google.com']);
        OnlineOrder::create($base + ['phone' => '+77010000004']);

        $sources = collect(app(OnlineFunnel::class)->build('this')['sources'])->keyBy('source');

        $this->assertSame(2, $sources['instagram']['orders']);
        $this->assertSame(1, $sources['google.com']['orders']);
        $this->assertSame(1, $sources['Прямой заход или не определён']['orders']);
        $this->assertSame(0, $sources['instagram']['paid']);
    }
}
