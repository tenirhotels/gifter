<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateUser extends Command
{
    protected $signature = 'gifter:create-user';

    protected $description = 'Создать пользователя панели (пароль вводится интерактивно и нигде не хранится в открытом виде)';

    public function handle(): int
    {
        $name = text('Имя', required: true);
        $email = text('Email', required: true, validate: fn ($value) => Validator::make(['email' => $value], ['email' => 'email|unique:users,email'])->errors()->first('email') ?: null);
        $password = password('Пароль (не короче 10 символов)', required: true, validate: fn ($value) => mb_strlen($value) < 10 ? 'Слишком короткий пароль.' : null);

        User::create(['name' => $name, 'email' => $email, 'password' => $password, 'is_active' => true]);
        $this->info("Пользователь {$email} создан.");

        return self::SUCCESS;
    }
}
