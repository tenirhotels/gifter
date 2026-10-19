<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Создать пользователя панели.
 *
 * Интерактивно (есть терминал): пароль вводится и нигде не хранится в открытом виде.
 * Без терминала — хостинг без SSH, запуск из панели (Plesk → Laravel → Artisan):
 *   gifter:create-user --name="Иван" --email=admin@example.com --role=super_admin
 * Пароль генерируется и показывается один раз; его нужно сменить после входа.
 * Пароль параметром не принимается — он остался бы в истории команд и журналах панели.
 */
class CreateUser extends Command
{
    protected $signature = 'gifter:create-user
        {--name= : Имя (без терминала)}
        {--email= : Email (без терминала)}
        {--role=* : Роль, можно несколько: super_admin, sales, sales_manager, sales_director, front_office_reservations, finance}';

    protected $description = 'Создать пользователя панели: интерактивно или параметрами (тогда пароль генерируется и показывается один раз)';

    public function handle(): int
    {
        return $this->option('email') ? $this->fromOptions() : $this->interactive();
    }

    private function fromOptions(): int
    {
        $name = trim((string) $this->option('name'));
        $email = trim((string) $this->option('email'));
        $roles = $this->option('role') ?: (User::query()->exists() ? [] : [Role::SuperAdmin->value]);

        $validator = Validator::make(['name' => $name, 'email' => $email, 'roles' => $roles], [
            'name' => 'required|max:255',
            'email' => 'required|email|unique:users,email',
            'roles' => 'required|array|min:1',
            'roles.*' => 'in:'.implode(',', array_map(fn (Role $role) => $role->value, Role::cases())),
        ], ['roles.required' => 'Укажите роль: --role=super_admin (или другую).']);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $password = Str::password(16, symbols: false);
        User::create(['name' => $name, 'email' => $email, 'password' => $password, 'is_active' => true, 'roles' => $roles]);
        $this->info("Пользователь {$email} создан.");
        $this->warn("Пароль (показывается один раз, смените после входа): {$password}");
        $this->twoFactorNotice();

        return self::SUCCESS;
    }

    private function interactive(): int
    {
        $name = text('Имя', required: true);
        $email = text('Email', required: true, validate: fn ($value) => Validator::make(['email' => $value], ['email' => 'email|unique:users,email'])->errors()->first('email') ?: null);
        $password = password('Пароль (от 12 символов, буквы и цифры)', required: true,
            validate: fn ($value) => Validator::make(['password' => $value], ['password' => Password::default()])->errors()->first('password') ?: null);

        $roles = multiselect('Роли', collect(Role::cases())->mapWithKeys(fn (Role $role) => [$role->value => $role->getLabel()])->all(),
            default: User::query()->exists() ? [] : [Role::SuperAdmin->value], required: true);

        User::create(['name' => $name, 'email' => $email, 'password' => $password, 'is_active' => true, 'roles' => $roles]);
        $this->info("Пользователь {$email} создан.");
        $this->twoFactorNotice();

        return self::SUCCESS;
    }

    private function twoFactorNotice(): void
    {
        $this->line('При первом входе нужно настроить двухфакторный вход: приложение Google Authenticator или аналог, затем сохранить коды восстановления.');
    }
}
