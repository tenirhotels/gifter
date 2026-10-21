<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Язык интерфейса: русский, казахский, английский; не переведённое — по-русски. */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_language_is_saved_to_session_and_profile(): void
    {
        $user = User::factory()->create(['roles' => [Role::Sales]]);

        $this->actingAs($user)->from('/admin')->post('/locale/kk')->assertRedirect('/admin');

        $this->assertSame('kk', $user->refresh()->locale);
        $this->assertSame('kk', session('locale'));
        $this->post('/locale/de')->assertNotFound();
    }

    public function test_interface_follows_user_language(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::Sales], 'locale' => 'kk']))->get('/admin')->assertOk()->assertSee('Менің сатылымдарым');
        $this->assertSame('Пайдаланылған', CertificateStatus::Used->getLabel());
    }

    public function test_english_interface(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::Sales], 'locale' => 'en']))->get('/admin')->assertOk()->assertSee('My sales');
        $this->assertSame('Used', CertificateStatus::Used->getLabel());
    }

    public function test_russian_is_the_default_and_untranslated_text_stays_russian(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::Sales]]))->get('/admin')->assertOk()->assertSee('Мои продажи');
        $this->assertSame('Использован', CertificateStatus::Used->getLabel());
        $this->assertSame('Текст без перевода', __('Текст без перевода'));
    }

    public function test_translation_files_are_complete(): void
    {
        $kk = json_decode(file_get_contents(lang_path('kk.json')), true);
        $en = json_decode(file_get_contents(lang_path('en.json')), true);

        $this->assertSame(array_keys($kk), array_keys($en), 'kk.json и en.json — один набор ключей');
        foreach ([$kk, $en] as $file) {
            foreach ($file as $key => $value) {
                $this->assertNotSame('', trim($value), "Пустой перевод: {$key}");
            }
        }
        // Названия всех статусов сертификата переведены.
        foreach (CertificateStatus::cases() as $status) {
            $this->assertArrayHasKey($status->getLabel(), $kk);
        }
    }

    public function test_application_timezone_defaults_to_almaty(): void
    {
        $this->assertSame('Asia/Almaty', config('app.timezone'));
    }

    public function test_every_interface_text_in_code_has_kazakh_and_english_translation(): void
    {
        foreach (['kk', 'en'] as $lang) {
            $this->artisan('gifter:lang-missing', ['--lang' => $lang])->expectsOutputToContain('0 без перевода')->assertSuccessful();
        }
    }

    public function test_translation_files_have_same_keys_and_no_unused_keys(): void
    {
        $kk = json_decode(file_get_contents(lang_path('kk.json')), true);
        $en = json_decode(file_get_contents(lang_path('en.json')), true);
        $this->assertSame(array_keys($kk), array_keys($en));
        $this->artisan('gifter:lang-missing', ['--unused' => true])->expectsOutputToContain('0 ключей без использования')->assertSuccessful();
    }

    public function test_messages_with_values_are_translated_with_placeholders(): void
    {
        app()->setLocale('kk');
        $this->assertSame('Комиссиялар: Айдар', __('Комиссии: :p1', ['p1' => 'Айдар']));
        app()->setLocale('en');
        $this->assertSame('Booking No. 77 was not found in PMS. Check the number.', __('Бронь № :p1 не найдена в PMS. Проверьте номер.', ['p1' => 77]));
    }

    public function test_public_verification_page_follows_lang_parameter(): void
    {
        $this->get('/v/'.str_repeat('a', 32).'?lang=en')->assertNotFound()->assertSee('Certificate not found');
        $this->get('/v/'.str_repeat('a', 32).'?lang=kk')->assertNotFound()->assertSee('Сертификат табылмады');
    }
}
