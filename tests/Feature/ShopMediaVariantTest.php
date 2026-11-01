<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Service;
use App\Models\ShopSetting;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Фото витрины: WebP нужной ширины, кеш на год, оригинал без WebP; разметка с srcset. */
class ShopMediaVariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function photo(string $name = 'photo123.jpg', int $w = 1600, int $h = 900): void
    {
        $image = imagecreatetruecolor($w, $h);
        for ($i = 0; $i < 60; $i++) {
            imagefilledrectangle($image, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        ob_start();
        imagejpeg($image, null, 95);
        Storage::disk('public')->put('shop/'.$name, (string) ob_get_clean());
    }

    public function test_webp_variant_has_requested_width_is_smaller_and_cached_for_a_year(): void
    {
        $this->photo();
        $original = Storage::disk('public')->size('shop/photo123.jpg');

        $response = $this->get('/media/photo123.jpg?w=720', ['Accept' => 'image/avif,image/webp,*/*']);

        $response->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertStringContainsString('max-age=31536000', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('Accept', $response->headers->get('Vary'));
        $path = Storage::disk('public')->path('shop/v/720/photo123.webp');
        $this->assertFileExists($path);
        $this->assertSame(720, getimagesize($path)[0]);
        $this->assertLessThan($original, filesize($path));
    }

    public function test_small_images_are_not_upscaled(): void
    {
        $this->photo('tiny.jpg', 300, 200);

        $this->get('/media/tiny.jpg?w=1200', ['Accept' => 'image/webp'])->assertOk();

        $this->assertSame(300, getimagesize(Storage::disk('public')->path('shop/v/1200/tiny.webp'))[0]);
    }

    public function test_browser_without_webp_and_foreign_widths_get_the_original(): void
    {
        $this->photo();

        $this->get('/media/photo123.jpg?w=720', ['Accept' => 'image/png,*/*'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/media/photo123.jpg?w=999', ['Accept' => 'image/webp'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/media/photo123.jpg', ['Accept' => 'image/webp'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertFileDoesNotExist(Storage::disk('public')->path('shop/v/999/photo123.webp'));
    }

    public function test_only_images_from_the_shop_folder_are_served(): void
    {
        $this->get('/media/missing.jpg')->assertNotFound();
        $this->get('/media/..%2F.env')->assertNotFound();
        Storage::disk('public')->put('shop/notes.txt', 'x');
        $this->get('/media/notes.txt')->assertNotFound();
    }

    public function test_pages_use_srcset_and_the_hero_is_a_prioritised_image(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');
        $this->photo('hero123.jpg');
        $this->photo('card123.jpg');
        ShopSetting::current()->fill(['hero_image_path' => 'hero123.jpg'])->save();
        $service = Service::factory()->create();
        app(ServicePricing::class)->addPrice($service, '50000', Carbon::parse('2026-01-01'));
        $package = Package::create(['name' => 'Вечер', 'validity_value' => 3, 'validity_unit' => 'months', 'sold_online' => true, 'image_path' => 'card123.jpg']);
        $package->items()->create(['service_id' => $service->id, 'quantity' => 1]);

        $home = $this->get('/')->assertOk();
        $home->assertSee('class="bg"', false)->assertSee('fetchpriority="high"', false)->assertSee('rel="preload" as="image"', false)
            ->assertSee('/media/hero123.jpg?w=1600 1600w', false)->assertDontSee("background-image:url('", false)
            ->assertSee('/media/card123.jpg?w=480 480w', false)->assertSee('width="720" height="480"', false)->assertSee('loading="lazy"', false);
        $this->get('/order/'.$package->id)->assertSee('/media/card123.jpg?w=1200 1200w', false)->assertSee('width="1200" height="900"', false);
    }
}
