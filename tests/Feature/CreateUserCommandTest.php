<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Создание пользователя без терминала (хостинг без SSH, запуск из панели). */
class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_user_from_options_is_admin_with_generated_password(): void
    {
        $this->artisan('gifter:create-user', ['--name' => 'Администратор', '--email' => 'admin@example.test'])
            ->expectsOutputToContain('Пользователь admin@example.test создан.')
            ->expectsOutputToContain('Пароль (показывается один раз')
            ->assertSuccessful();

        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole(Role::SuperAdmin));
        $this->assertTrue($user->is_active);
        $this->assertFalse(Hash::check('', $user->password));
    }

    public function test_options_are_validated(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->artisan('gifter:create-user', ['--name' => 'X', '--email' => 'taken@example.test', '--role' => ['sales']])->assertFailed();
        $this->artisan('gifter:create-user', ['--name' => 'X', '--email' => 'new@example.test'])
            ->expectsOutputToContain('Укажите роль')->assertFailed();
        $this->artisan('gifter:create-user', ['--name' => 'X', '--email' => 'new@example.test', '--role' => ['boss']])->assertFailed();

        $this->artisan('gifter:create-user', ['--name' => 'Front', '--email' => 'fo@example.test', '--role' => ['front_office_reservations']])->assertSuccessful();
        $this->assertTrue(User::where('email', 'fo@example.test')->firstOrFail()->hasRole(Role::FrontOffice));
    }

    public function test_requests_behind_cloudflare_are_treated_as_https(): void
    {
        $this->get('/admin/login', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.5'])
            ->assertOk()
            ->assertSee('https://localhost', false);
    }
}
