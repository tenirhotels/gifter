<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

/** Тестовые пользователи по одному на роль — только для локальной проверки. */
class CreateDemoUsers extends Command
{
    protected $signature = 'gifter:demo-users';

    protected $description = 'Создать тестовых пользователей для всех ролей (только APP_ENV=local)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Команда доступна только при APP_ENV=local.');

            return self::FAILURE;
        }

        $password = password('Общий пароль для тестовых пользователей (от 12 символов, буквы и цифры)', required: true,
            validate: fn ($value) => Validator::make(['password' => $value], ['password' => Password::default()])->errors()->first('password') ?: null);

        $rows = [];
        foreach ([
            'admin' => Role::SuperAdmin,
            'sales' => Role::Sales,
            'manager' => Role::SalesManager,
            'director' => Role::SalesDirector,
            'frontoffice' => Role::FrontOffice,
            'finance' => Role::Finance,
        ] as $login => $role) {
            $email = "{$login}@gifter.local";
            User::updateOrCreate(['email' => $email], [
                'name' => $role->getLabel(),
                'password' => $password,
                'roles' => [$role],
                'is_active' => true,
            ]);
            $rows[] = [$email, $role->getLabel()];
        }

        $this->table(['Email', 'Роль'], $rows);
        $this->info('Готово. Вход: /admin');
        $this->line('При первом входе каждый пользователь настраивает двухфакторный вход. Для быстрой локальной проверки можно указать GIFTER_REQUIRE_2FA=false в .env.');

        return self::SUCCESS;
    }
}
