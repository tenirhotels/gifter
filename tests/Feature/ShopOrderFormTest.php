<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Enums\Role;
use App\Filament\Pages\ShopSettings;
use App\Models\Customer;
use App\Models\OnlineOrder;
use App\Models\Package;
use App\Models\Service;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\OnlineOrderManager;
use App\Services\ServicePricing;
use App\Support\KazakhstanBin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Настройки формы заказа: блок «Для кого» и корпоративная покупка (компания и БИН). */
class ShopOrderFormTest extends TestCase
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
    }

    /** @return array<string, mixed> */
    private function form(array $override = []): array
    {
        return array_merge(['last_name' => 'Иванов', 'first_name' => 'Иван', 'phone' => '8 701 123 45 67', 'email' => 'ivan@example.com',
            'recipient_name' => 'Петрова Анна', 'gift_message' => 'С днём рождения!', 'delivery_method' => 'pickup', 'consent' => '1'], $override);
    }

    public function test_recipient_block_and_corporate_checkbox_are_shown_by_default_and_can_be_hidden(): void
    {
        $this->get('/order/'.$this->package->id)->assertOk()->assertSee('name="recipient_name"', false)->assertSee('name="is_corporate"', false);

        ShopSetting::current()->fill(['order_show_recipient' => false, 'order_corporate_enabled' => false])->save();

        $this->get('/order/'.$this->package->id)->assertOk()->assertDontSee('name="recipient_name"', false)->assertDontSee('name="gift_message"', false)->assertDontSee('name="is_corporate"', false);
    }

    public function test_hidden_recipient_block_is_ignored_on_the_server(): void
    {
        ShopSetting::current()->fill(['order_show_recipient' => false])->save();

        $this->post('/order/'.$this->package->id, $this->form())->assertRedirect();

        $order = OnlineOrder::query()->firstOrFail();
        $this->assertNull($order->recipient_name);
        $this->assertNull($order->gift_message);
    }

    public function test_corporate_purchase_needs_company_and_valid_bin(): void
    {
        $this->post('/order/'.$this->package->id, $this->form(['is_corporate' => '1']))->assertSessionHasErrors(['company', 'bin']);
        $this->post('/order/'.$this->package->id, $this->form(['is_corporate' => '1', 'company' => 'ТОО Ромашка', 'bin' => '123456789012']))->assertSessionHasErrors('bin');
        $this->assertSame(0, OnlineOrder::query()->count());

        $bin = $this->validBin();
        $this->post('/order/'.$this->package->id, $this->form(['is_corporate' => '1', 'company' => ' ТОО Ромашка ', 'bin' => substr($bin, 0, 6).' '.substr($bin, 6)]))->assertRedirect();

        $order = OnlineOrder::query()->firstOrFail();
        $this->assertTrue($order->is_corporate);
        $this->assertSame('ТОО Ромашка', $order->company);
        $this->assertSame($bin, $order->bin);
    }

    public function test_company_fields_are_ignored_without_the_checkbox_or_when_disabled(): void
    {
        $this->post('/order/'.$this->package->id, $this->form(['company' => 'ТОО Ромашка', 'bin' => $this->validBin()]))->assertRedirect();
        $this->assertNull(OnlineOrder::query()->firstOrFail()->company);

        OnlineOrder::query()->delete();
        ShopSetting::current()->fill(['order_corporate_enabled' => false])->save();
        $this->post('/order/'.$this->package->id, $this->form(['is_corporate' => '1', 'company' => 'ТОО Ромашка', 'bin' => $this->validBin(), 'email' => 'other@example.com', 'phone' => '8 701 555 44 33']))->assertRedirect();
        $this->assertFalse(OnlineOrder::query()->firstOrFail()->is_corporate);
    }

    public function test_processing_creates_company_customer_and_keeps_existing_customer(): void
    {
        $staff = User::factory()->create(['roles' => [Role::SuperAdmin]]);
        $bin = $this->validBin();
        $order = OnlineOrder::create(['package_id' => $this->package->id, 'last_name' => 'Иванов', 'first_name' => 'Иван', 'phone' => '+77011234567', 'is_corporate' => true, 'company' => 'ТОО Ромашка', 'bin' => $bin]);

        $certificate = app(OnlineOrderManager::class)->process($order, $staff);

        $customer = $certificate->customer;
        $this->assertSame(CustomerType::Company, $customer->type);
        $this->assertSame('ТОО Ромашка', $customer->company);
        $this->assertSame($bin, $customer->bin);

        $existing = Customer::factory()->create(['phone' => '+77012223344']);
        $second = OnlineOrder::create(['package_id' => $this->package->id, 'last_name' => 'Иванов', 'first_name' => 'Иван', 'phone' => '+77012223344', 'is_corporate' => true, 'company' => 'ТОО Лотос', 'bin' => $bin]);
        $draft = app(OnlineOrderManager::class)->process($second, $staff);

        $this->assertSame($existing->id, $draft->customer_id);
        $this->assertStringContainsString('ТОО Лотос', (string) $draft->notes);
    }

    public function test_settings_are_saved_by_admin(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));

        Livewire::test(ShopSettings::class)
            ->assertFormSet(['order_show_recipient' => true, 'order_corporate_enabled' => true])
            ->fillForm(['order_show_recipient' => false, 'order_corporate_enabled' => false])
            ->call('save')->assertHasNoFormErrors();

        $setting = ShopSetting::current();
        $this->assertFalse($setting->showsRecipient());
        $this->assertFalse($setting->corporateEnabled());
    }

    private function validBin(): string
    {
        for ($n = 100000000000; $n < 100000000500; $n++) {
            if (KazakhstanBin::isValid((string) $n)) {
                return (string) $n;
            }
        }
        $this->fail('Не нашёл тестовый БИН');
    }
}
