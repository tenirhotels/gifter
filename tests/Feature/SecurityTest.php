<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Auth\EditProfile;
use App\Filament\Pages\Security;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Mail\BackupArchiveMail;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Recipient;
use App\Models\User;
use App\Services\ContactRevealGuard;
use App\Services\SecurityAudit;
use App\Services\UserSecurity;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Защита данных на shared-хостинге: шифрование контактов, обязательный 2FA, заголовки, проверка, бэкапы. */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['roles' => [Role::SuperAdmin]]);
    }

    public function test_contacts_are_encrypted_at_rest_and_searchable_by_hash(): void
    {
        $customer = Customer::create(['last_name' => 'Смирнова', 'first_name' => 'Анна', 'phone' => '+7 701 123 45 67', 'email' => 'Anna@Example.com', 'notes' => 'VIP']);
        Recipient::create(['last_name' => 'Ким', 'first_name' => 'Ли', 'phone' => '+7 777 000 00 01']);

        $raw = DB::table('customers')->find($customer->id);
        $this->assertStringNotContainsString('701', $raw->phone);
        $this->assertStringNotContainsString('Example', $raw->email);
        $this->assertStringNotContainsString('VIP', $raw->notes);
        $this->assertSame('+77011234567', $customer->fresh()->phone, 'Хранится в международном формате без пробелов');

        $this->assertTrue(Customer::whereContact('8 (701) 123-45-67')->first()->is($customer));
        $this->assertTrue(Customer::whereContact('anna@example.com')->first()->is($customer));
        $this->assertNull(Customer::whereContact('Смирнова')->first());
        $this->assertSame(1, Recipient::whereContact('+77770000001')->count());

        $customer->update(['phone' => '+7 702 000 00 00']);
        $this->assertNull(Customer::whereContact('+7 701 123 45 67')->first());
        $this->assertNotNull(Customer::whereContact('+7 702 000 00 00')->first());

        $audit = AuditLog::where('auditable_type', $customer->getMorphClass())->get();
        $this->assertStringNotContainsString('701', $audit->toJson());
        $this->assertStringNotContainsString('702', $audit->toJson());
        $this->assertSame('[скрыто]', $audit->last()->after['phone']);
    }

    public function test_customer_list_finds_by_full_phone(): void
    {
        $this->actingAs($this->admin());
        $found = Customer::factory()->create(['phone' => '+7 701 555 44 33']);
        $other = Customer::factory()->create(['phone' => '+7 705 111 22 33']);

        Livewire::test(ListCustomers::class)->searchTable('87015554433')
            ->assertCanSeeTableRecords([$found])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_two_factor_is_required_before_using_the_panel(): void
    {
        $user = User::factory()->withoutTwoFactor()->create(['roles' => [Role::Sales]]);

        $this->actingAs($user)->get('/admin')->assertRedirectContains('multi-factor-authentication');
        $this->actingAs(User::factory()->create(['roles' => [Role::Sales]]))->get('/admin')->assertOk();
    }

    public function test_two_factor_secret_is_encrypted_and_not_audited(): void
    {
        $user = User::factory()->withoutTwoFactor()->create(['roles' => [Role::Sales]]);
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->assertNotSame('JBSWY3DPEHPK3PXP', DB::table('users')->find($user->id)->app_authentication_secret);
        $this->assertTrue(AuditLog::where('event', 'two_factor_enabled')->where('auditable_id', $user->id)->exists());
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', AuditLog::all()->toJson());
    }

    public function test_admin_resets_two_factor_with_reason(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['roles' => [Role::FrontOffice]]);
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->assertActionHidden(TestAction::make('resetTwoFactor')->table($admin))
            ->callAction(TestAction::make('resetTwoFactor')->table($user), ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);
        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('resetTwoFactor')->table($user), ['reason' => 'Потерял телефон'])
            ->assertHasNoActionErrors();

        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->assertSame(['reason' => 'Потерял телефон'], AuditLog::where('event', 'two_factor_reset')->sole()->after);

        $this->expectException(DomainRuleViolation::class);
        app(UserSecurity::class)->resetTwoFactor($admin, 'сам себе');
    }

    public function test_only_admin_resets_two_factor(): void
    {
        $user = User::factory()->create(['roles' => [Role::FrontOffice]]);
        $this->actingAs(User::factory()->create(['roles' => [Role::SalesDirector]]));

        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/security')->assertForbidden();
        $this->assertTrue($user->fresh()->hasTwoFactor());
    }

    public function test_profile_changes_password_but_not_email(): void
    {
        $user = User::factory()->create(['roles' => [Role::Sales], 'email' => 'sales@example.test']);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->assertFormFieldDisabled('email')
            ->fillForm(['email' => 'other@example.test', 'password' => 'short', 'passwordConfirmation' => 'short', 'currentPassword' => 'password'])
            ->call('save')
            ->assertHasFormErrors(['password']);

        $this->assertSame('sales@example.test', $user->fresh()->email);
    }

    public function test_security_headers(): void
    {
        $this->get('/admin/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin');

        $this->get('/admin/login', ['X-Forwarded-Proto' => 'https'])->assertHeader('Strict-Transport-Security');
    }

    public function test_security_check_finds_unsafe_settings(): void
    {
        config(['app.debug' => true, 'database.default' => 'sqlite', 'app.url' => 'http://gifter.example.test']);

        $this->artisan('gifter:security-check')
            ->expectsOutputToContain('APP_DEBUG=false')
            ->expectsOutputToContain('DB_CONNECTION=mysql')
            ->assertFailed();

        $this->assertGreaterThan(0, Security::problems());
    }

    public function test_security_check_passes_on_a_configured_server(): void
    {
        Storage::fake('backups');
        Storage::disk('backups')->put('gifter/gifter-2026-10-19.zip', 'archive');
        Http::fake(['*/.env' => Http::response('Not found', 404)]);
        $this->app['env'] = 'production';
        config([
            'app.debug' => false, 'app.url' => 'https://gifter.example.test',
            'session.secure' => true, 'session.encrypt' => true, 'logging.channels.single.level' => 'warning',
            'gifter.security.contact_hash_key' => 'fictional-hash-key-for-tests', 'backup.backup.password' => 'fictional-archive-pass',
            'gifter.backup.mail_to' => 'owner@example.test', 'gifter.require_payment_reconciled' => true,
        ]);

        $results = app(SecurityAudit::class)->run();

        // Тесты идут на SQLite — это единственное, что проверка должна отметить.
        $this->assertSame(['База — MySQL, не файл SQLite'], collect($results)->where('status', '!=', SecurityAudit::OK)->pluck('check')->all());
    }

    public function test_env_reachable_over_http_is_an_error(): void
    {
        Http::fake(['*/.env' => Http::response("APP_KEY=base64:fictional\n", 200)]);
        $this->app['env'] = 'production';
        config(['app.url' => 'https://gifter.example.test']);

        $result = collect(app(SecurityAudit::class)->run())->firstWhere('check', '.env не отдаётся из интернета');

        $this->assertSame(SecurityAudit::FAIL, $result['status']);
    }

    public function test_backup_is_mailed_only_encrypted(): void
    {
        Mail::fake();
        Storage::fake('backups');
        Storage::disk('backups')->put('gifter/gifter-2026-10-19.zip', 'archive');
        config(['gifter.backup.mail_to' => 'owner@example.test', 'backup.backup.password' => null]);

        $this->artisan('gifter:backup-mail')->assertFailed();
        Mail::assertNothingSent();

        config(['backup.backup.password' => 'fictional-archive-pass']);
        $this->artisan('gifter:backup-mail')->assertSuccessful();
        Mail::assertSent(BackupArchiveMail::class, fn (BackupArchiveMail $mail) => $mail->hasTo('owner@example.test') && str_ends_with($mail->path, '.zip'));
    }

    public function test_security_page_and_audited_backup_download(): void
    {
        Storage::fake('backups');
        Storage::disk('backups')->put('gifter/gifter-2026-10-19.zip', 'archive');
        $admin = $this->admin();
        $this->actingAs($admin);
        app(SecurityAudit::class)->run(checkHttp: false);

        $this->get('/admin/security')->assertOk()->assertSee('Проверка настроек')->assertSee('архив зашифрован');

        Livewire::test(Security::class)->callAction('download')->assertFileDownloaded('gifter-2026-10-19.zip');
        $this->assertSame(['file' => 'gifter-2026-10-19.zip'], AuditLog::where('event', 'backup_downloaded')->sole()->after);
    }

    public function test_create_user_mentions_two_factor_and_password_rules(): void
    {
        $this->artisan('gifter:create-user', ['--name' => 'Админ', '--email' => 'admin@example.test'])
            ->expectsOutputToContain('двухфакторный вход')
            ->assertSuccessful();
    }

    public function test_contact_reveals_are_limited_per_day_and_logged(): void
    {
        config(['gifter.security.reveal_daily_limit' => 2]);
        $this->actingAs(User::factory()->create(['roles' => [Role::Sales]]));
        [$a, $b, $c] = Customer::factory()->count(3)->create()->all();
        $guard = app(ContactRevealGuard::class);

        $this->assertTrue($guard->reveal($a));
        $this->assertTrue($guard->reveal($b));
        $this->assertTrue($guard->reveal($a), 'Повторное открытие того же клиента не считается');
        $this->assertFalse($guard->reveal($c));
        $this->assertSame(1, AuditLog::where('event', 'contacts_reveal_limit')->count());

        $this->actingAs($this->admin());
        $this->assertTrue($guard->reveal($c), 'Администратор не ограничен');
    }

    public function test_phone_must_be_international(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateCustomer::class)
            ->fillForm(['type' => 'individual', 'last_name' => 'Коннор', 'first_name' => 'Джон', 'phone' => '64564556'])
            ->call('create')
            ->assertHasFormErrors(['phone']);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['type' => 'individual', 'last_name' => 'Коннор', 'first_name' => 'Джон', 'phone' => '8 (701) 123-45-67'])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame('+77011234567', Customer::where('last_name', 'Коннор')->sole()->phone);

        $this->expectException(DomainRuleViolation::class);
        Recipient::create(['last_name' => 'Ким', 'first_name' => 'Ли', 'phone' => '123']);
    }
}
