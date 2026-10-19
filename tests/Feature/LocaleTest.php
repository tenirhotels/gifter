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

        $this->actingAs($user)->from('/admin')->get('/locale/kk')->assertRedirect('/admin');

        $this->assertSame('kk', $user->refresh()->locale);
        $this->assertSame('kk', session('locale'));
        $this->get('/locale/de')->assertNotFound();
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
}
