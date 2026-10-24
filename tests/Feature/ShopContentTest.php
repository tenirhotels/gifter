<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\ShopSettings;
use App\Models\ShopSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** FAQ и условия сертификата задаются в «Настройках сайта → Контент» на трёх языках. */
class ShopContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_texts_are_shown_on_all_languages(): void
    {
        $this->get('/faq?lang=ru')->assertOk()->assertSee('Как купить сертификат?');
        $this->get('/faq?lang=en')->assertOk()->assertDontSee('Как купить сертификат?');
        $this->get('/terms?lang=ru')->assertOk()->assertSee('Неиспользованные услуги не компенсируются.');
        $this->get('/terms?lang=kk')->assertOk()->assertDontSee('Неиспользованные услуги не компенсируются.');
    }

    public function test_site_uses_custom_items_with_language_fallback_and_escaping(): void
    {
        ShopSetting::create([
            'faq_items' => [['q' => ['ru' => 'Есть ли парковка?', 'en' => 'Is there parking?'], 'a' => ['ru' => "Да.\nБесплатно <b>всем</b>.", 'en' => 'Yes.']]],
            'terms_items' => [['t' => ['ru' => 'Только по предъявлению', 'kk' => 'Көрсеткенде ғана']]],
        ]);

        $this->get('/faq?lang=en')->assertSee('Is there parking?')->assertSee('Yes.')->assertDontSee('Как купить сертификат?');
        $this->get('/faq?lang=kk')->assertSee('Есть ли парковка?')->assertSee('&lt;b&gt;всем&lt;/b&gt;', false);
        $this->get('/terms?lang=kk')->assertSee('Көрсеткенде ғана');
        $this->get('/terms?lang=en')->assertSee('Только по предъявлению')->assertDontSee('Неиспользованные услуги');
    }

    public function test_admin_edits_adds_and_removes_items(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));

        $page = Livewire::test(ShopSettings::class);
        $page->assertFormSet(fn (array $state) => count($state['faq_items']) === 8 && count($state['terms_items']) === 4);

        $page->fillForm([
            'faq_items' => [['q' => 'Есть ли парковка?', 'q_kk' => 'Тұрақ бар ма?', 'q_en' => '', 'a' => 'Да.', 'a_kk' => 'Иә.', 'a_en' => 'Yes.']],
            'terms_items' => [['t' => 'Пункт один', 't_kk' => '', 't_en' => 'Point one']],
        ])->call('save')->assertHasNoFormErrors();

        $setting = ShopSetting::current();
        $this->assertCount(1, $setting->faq_items);
        $this->assertSame(['ru' => 'Есть ли парковка?', 'kk' => 'Тұрақ бар ма?'], $setting->faq_items[0]['q']);
        $this->assertSame([['t' => ['ru' => 'Пункт один', 'en' => 'Point one']]], $setting->terms_items);
        $this->get('/faq?lang=kk')->assertSee('Тұрақ бар ма?');
    }

    public function test_pdf_terms_stay_from_config(): void
    {
        ShopSetting::create(['terms_items' => [['t' => ['ru' => 'Только на сайте']]]]);

        $this->assertSame('Только на сайте', ShopSetting::current()->terms('ru')[0]);
        $this->assertNotEmpty(config('gifter.certificate.terms'));
        $this->assertNotContains('Только на сайте', config('gifter.certificate.terms'));
    }
}
