<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\AuditLog;
use App\Models\OnlineOrder;
use App\Models\Package;
use App\Models\PmsService;
use App\Models\ShopSetting;
use App\Models\User;
use App\Support\AuditPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Журнал аудита «по-человечески»: события, объекты, поля и значения — на понятном языке. */
class AuditReadableTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Нуржан', 'roles' => [Role::SuperAdmin]]);
        $this->actingAs($this->admin);
    }

    public function test_user_creation_reads_as_a_sentence_with_labels_and_role_names(): void
    {
        User::factory()->create(['name' => 'Kamila', 'email' => 'reservations@example.test', 'roles' => [Role::Sales], 'is_active' => true]);
        $log = AuditLog::query()->where('event', 'created')->where('auditable_type', (new User)->getMorphClass())->latest('id')->firstOrFail();

        $this->assertSame('Создание', AuditPresenter::event('created'));
        $this->assertSame('Пользователь «Kamila»', AuditPresenter::subject($log));
        $summary = AuditPresenter::summary($log);
        $this->assertStringContainsString('Имя: Kamila', $summary);
        $this->assertStringContainsString('Активен: да', $summary);
        $this->assertStringContainsString('Роли: '.Role::Sales->getLabel(), $summary);
        $this->assertStringNotContainsString('is_active', $summary);
        $this->assertStringNotContainsString("'", $summary);
        $this->assertStringNotContainsString('password', $summary);

        Livewire::test(ListAuditLogs::class)->assertSee('Пользователь «Kamila»')->assertSee('Создание')->assertDontSee('User #');
    }

    public function test_update_shows_before_and_after_and_services_and_settings_have_human_names(): void
    {
        $user = User::factory()->create(['name' => 'Диана', 'roles' => [Role::Sales]]);
        $user->update(['roles' => [Role::SalesDirector], 'is_active' => false]);
        $log = AuditLog::query()->where('event', 'updated')->latest('id')->firstOrFail();
        $summary = AuditPresenter::summary($log);
        $this->assertStringContainsString('Роли: '.Role::Sales->getLabel().' → '.Role::SalesDirector->getLabel(), $summary);
        $this->assertStringContainsString('Активен: да → нет', $summary);

        PmsService::create(['external_id' => '5057672', 'name' => 'Dinner à la carte', 'kind' => 'Meal', 'status' => 'Active', 'synced_at' => now()]);
        $pms = AuditLog::query()->where('auditable_type', (new PmsService)->getMorphClass())->latest('id')->firstOrFail();
        $this->assertSame('Услуга из PMS «Dinner à la carte»', AuditPresenter::subject($pms));
        $this->assertStringContainsString('Код в PMS: 5057672', AuditPresenter::summary($pms));

        $setting = ShopSetting::create(['ga4_id' => 'G-ABC12345']);
        AuditLog::record('shop_settings_changed', $setting, ['ga4_id' => null], ['ga4_id' => 'G-ABC12345', 'accent_color' => '#5F7872']);
        $shop = AuditLog::query()->where('event', 'shop_settings_changed')->firstOrFail();
        $this->assertSame('Изменены настройки сайта', AuditPresenter::event('shop_settings_changed'));
        $this->assertSame('Настройки сайта № '.$setting->id, AuditPresenter::subject($shop));
        $this->assertStringContainsString('Google Analytics: — → G-ABC12345', AuditPresenter::summary($shop));
    }

    public function test_hidden_personal_values_stay_hidden_and_names_of_clients_are_not_taken_from_live_data(): void
    {
        $package = Package::create(['name' => 'Пакет', 'validity_value' => 1, 'validity_unit' => 'months', 'sold_online' => true]);
        $order = OnlineOrder::create(['package_id' => $package->id, 'last_name' => 'Иванов', 'first_name' => 'Иван', 'phone' => '+77011234567', 'email' => 'ivan@example.test']);
        $log = AuditLog::query()->where('auditable_type', (new OnlineOrder)->getMorphClass())->latest('id')->firstOrFail();

        $subject = AuditPresenter::subject($log);
        $summary = AuditPresenter::summary($log);
        $this->assertSame('Заявка с сайта № '.$order->id, $subject);
        foreach (['Иванов', 'Иван', '7011234567', 'ivan@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $subject.' '.$summary);
        }
        $this->assertStringContainsString('Статус: Новая', $summary);
    }

    public function test_detail_page_shows_a_field_table_and_escapes_values(): void
    {
        $user = User::factory()->create(['name' => 'Тест', 'roles' => [Role::Sales]]);
        $user->update(['name' => '<script>alert(1)</script>']);
        $log = AuditLog::query()->where('event', 'updated')->latest('id')->firstOrFail();

        Livewire::test(ViewAuditLog::class, ['record' => $log->id])->assertSee('Поле')->assertSee('Было')->assertSee('Стало')->assertSee('Имя')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_unknown_events_and_fields_fall_back_to_readable_text(): void
    {
        $this->assertSame('some new event', AuditPresenter::event('some_new_event'));
        $this->assertSame('custom_field', AuditPresenter::field('custom_field'));
        $this->assertSame('Выгрузка отчёта по продавцам', AuditPresenter::event('seller_report_exported'));
        $this->assertSame('danger', AuditPresenter::eventColor('deleted'));
    }
}
