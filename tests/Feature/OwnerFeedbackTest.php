<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\HistoryEvent;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\ValidityUnit;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Certificates\Pages\CreateCertificate;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Filament\Resources\Certificates\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\RelationManagers\PricesRelationManager;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Recipient;
use App\Models\Service;
use App\Models\User;
use App\Services\CertificateManager;
use App\Services\PaymentManager;
use App\Services\ServicePricing;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Замечания владельца после теста фаз 1–3 (21.10.2026). */
class OwnerFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Service $room;

    private Service $spa;

    private Package $weekend;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $pricing = app(ServicePricing::class);
        $this->room = Service::factory()->create(['name' => 'Проживание']);
        $pricing->addPrice($this->room, '150000', Carbon::parse('2026-01-01'), cost: '60000');
        $this->spa = Service::factory()->create(['name' => 'SPA']);
        $pricing->addPrice($this->spa, '40000', Carbon::parse('2026-01-01'), cost: '12000');
        $this->weekend = Package::create(['name' => 'Уикенд', 'validity_value' => 2, 'validity_unit' => 'months']);
        $this->weekend->items()->create(['service_id' => $this->room->id, 'quantity' => 2]);
    }

    private function as(Role ...$roles): User
    {
        $user = User::factory()->create(['roles' => $roles]);
        $this->actingAs($user);

        return $user;
    }

    private function draft(array $data = []): Certificate
    {
        return app(CertificateManager::class)->createDraft($data + [
            'package_id' => $this->weekend->id,
            'customer_id' => Customer::factory()->create()->id,
        ]);
    }

    // 1. ФИО раздельно, компания с БИН

    public function test_full_name_is_built_from_parts(): void
    {
        $customer = Customer::create(['last_name' => 'Иванов', 'first_name' => 'Иван', 'middle_name' => 'Иванович']);

        $this->assertSame('Иванов Иван Иванович', $customer->full_name);
    }

    public function test_company_customer_requires_valid_bin(): void
    {
        $base = ['type' => 'company', 'company' => 'ТОО Альфа', 'last_name' => 'Петров', 'first_name' => 'Пётр'];

        Livewire::test(CreateCustomer::class)->fillForm($base)->call('create')->assertHasFormErrors(['bin' => 'required']);
        Livewire::test(CreateCustomer::class)->fillForm($base + ['bin' => '940140000386'])->call('create')->assertHasFormErrors(['bin']);
        Livewire::test(CreateCustomer::class)->fillForm($base + ['bin' => '940140000385'])->call('create')->assertHasNoFormErrors();

        $this->assertSame('ТОО Альфа (Петров Пётр)', Customer::firstOrFail()->displayName());
    }

    public function test_individual_does_not_need_bin(): void
    {
        Livewire::test(CreateCustomer::class)
            ->fillForm(['type' => 'individual', 'last_name' => 'Сидоров', 'first_name' => 'Олег'])
            ->call('create')->assertHasNoFormErrors();
    }

    // 2. Получатель необязателен

    public function test_certificate_without_recipient_is_sold_and_recipient_added_later(): void
    {
        $certificate = $this->draft();
        app(PaymentManager::class)->markPaid(app(PaymentManager::class)->invoice($certificate, PaymentMethod::KaspiInvoice));
        app(CertificateManager::class)->issue($certificate);
        $this->assertNull($certificate->recipient_id);

        try {
            app(CertificateManager::class)->transition($certificate, CertificateStatus::Reserved);
            $this->fail('Бронь без получателя');
        } catch (DomainRuleViolation) {
        }

        $this->as(Role::Sales, Role::SalesManager);
        $recipient = Recipient::factory()->create();
        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->callAction('setRecipient', ['recipient_id' => $recipient->id]);

        $this->assertSame($recipient->id, $certificate->refresh()->recipient_id);
        $this->assertSame(HistoryEvent::RecipientChanged, $certificate->history()->first()->event);
    }

    // 3. Пакет — продукт со сроком действия

    public function test_validity_comes_from_package(): void
    {
        $certificate = $this->draft();

        $this->assertSame('2026-10-18', $certificate->valid_from->toDateString());
        $this->assertSame('2026-12-18', $certificate->valid_until->toDateString());
        $this->assertSame('300000.00', (string) $certificate->total_amount);
        $this->assertTrue($certificate->items()->first()->from_package);
        $this->assertSame('2 месяца с даты продажи', $this->weekend->validityLabel());
        $this->assertSame('21 день', ValidityUnit::Days->format(21));
        $this->assertSame('12 месяцев', ValidityUnit::Months->format(12));
    }

    public function test_package_is_required(): void
    {
        $this->expectException(DomainRuleViolation::class);
        app(CertificateManager::class)->createDraft(['customer_id' => Customer::factory()->create()->id]);
    }

    public function test_sales_cannot_set_dates_on_create(): void
    {
        $this->as(Role::Sales);
        Livewire::test(CreateCertificate::class)
            ->assertFormFieldHidden('sold_at')
            ->assertFormFieldDoesNotExist('valid_until')
            ->fillForm(['package_id' => $this->weekend->id, 'customer_id' => Customer::factory()->create()->id])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame('2026-12-18', Certificate::firstOrFail()->valid_until->toDateString());
    }

    public function test_package_change_replaces_base_items_and_keeps_extras(): void
    {
        $certificate = $this->draft();
        app(CertificateManager::class)->addItem($certificate, $this->spa, 1);
        $short = Package::create(['name' => 'Короткий', 'validity_value' => 30, 'validity_unit' => 'days']);

        app(CertificateManager::class)->updateDraft($certificate, ['package_id' => $short->id]);

        $this->assertSame(['SPA'], $certificate->items()->pluck('service_name_snapshot')->all());
        $this->assertSame('2026-11-17', $certificate->refresh()->valid_until->toDateString());
        $this->assertSame('40000.00', (string) $certificate->total_amount);
    }

    public function test_sales_removes_only_extras_manager_removes_package_items(): void
    {
        $this->as(Role::Sales);
        $certificate = $this->draft();
        $extra = app(CertificateManager::class)->addItem($certificate, $this->spa, 1);
        $base = $certificate->items()->where('from_package', true)->first();

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->assertActionHidden(TestAction::make('remove')->table($base))
            ->assertActionVisible(TestAction::make('remove')->table($extra));

        $this->as(Role::SalesManager);
        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $certificate, 'pageClass' => ViewCertificate::class])
            ->assertActionVisible(TestAction::make('remove')->table($base));
    }

    // 5. Исправление цены в день добавления

    public function test_price_added_today_can_be_fixed_until_used(): void
    {
        $service = Service::factory()->create();
        $old = app(ServicePricing::class)->addPrice($service, '10000', Carbon::parse('2026-01-01'));
        Carbon::setTestNow('2026-10-20 10:00:00');
        $new = app(ServicePricing::class)->addPrice($service, '12000', Carbon::parse('2026-11-01'));

        $this->assertFalse(app(ServicePricing::class)->isEditable($old->refresh()));
        app(ServicePricing::class)->updatePrice($new, '12500', Carbon::parse('2026-11-05'));

        $this->assertSame('12500.00', (string) $new->refresh()->price);
        $this->assertSame('2026-11-04', $old->refresh()->valid_until->toDateString());

        Carbon::setTestNow('2026-10-21 09:00:00');
        $this->expectException(DomainRuleViolation::class);
        app(ServicePricing::class)->updatePrice($new, '13000', Carbon::parse('2026-11-05'));
    }

    public function test_price_used_in_certificate_cannot_be_fixed(): void
    {
        $price = $this->spa->prices()->first();
        app(CertificateManager::class)->addItem($this->draft(), $this->spa, 1);

        $this->assertFalse(app(ServicePricing::class)->isEditable($price));
    }

    public function test_director_sees_fix_action_on_todays_price(): void
    {
        $service = Service::factory()->create();
        $price = app(ServicePricing::class)->addPrice($service, '5000', Carbon::parse('2026-01-01'));

        $this->as(Role::SalesDirector);
        Livewire::test(PricesRelationManager::class, ['ownerRecord' => $service, 'pageClass' => EditService::class])
            ->callAction(TestAction::make('fixPrice')->table($price), ['price' => '5500', 'valid_from' => '2026-01-01']);

        $this->assertSame('5500.00', (string) $price->refresh()->price);
    }

    // 6. Финансовые данные и контакты

    public function test_sales_manager_sees_price_and_paid_but_not_cost_or_margin(): void
    {
        $certificate = $this->draft();

        $this->as(Role::SalesManager);
        $this->get("/admin/certificates/{$certificate->id}")->assertOk()
            ->assertSee('Стоимость')->assertSee('Оплачено')
            ->assertDontSee('Себестоимость')->assertDontSee('Маржа');
        $this->get('/admin/payments')->assertForbidden();
    }

    public function test_contacts_are_masked_and_reveal_is_audited(): void
    {
        $customer = Customer::create(['last_name' => 'Смирнова', 'first_name' => 'Анна', 'phone' => '+7 701 123 45 67', 'email' => 'anna@example.com']);

        $user = $this->as(Role::Sales);
        $this->get('/admin/customers')->assertOk()->assertDontSee('123 45 67')->assertSee('•••• 4567')->assertDontSee('anna@example.com');

        Livewire::test(ListCustomers::class)->mountAction(TestAction::make('revealContacts')->table($customer));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'contacts_revealed', 'auditable_type' => Customer::class, 'auditable_id' => $customer->id, 'user_id' => $user->id,
        ]);
    }

    public function test_opening_edit_form_counts_as_reveal(): void
    {
        $customer = Customer::factory()->create();

        $this->as(Role::Sales);
        $this->get("/admin/customers/{$customer->id}/edit")->assertOk();

        $this->assertSame(1, AuditLog::where('event', 'contacts_revealed')->where('auditable_id', $customer->id)->count());
    }
}
