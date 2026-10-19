<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Role;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Имя')->required()->maxLength(255),
            TextInput::make('email')->label('Email')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
            TextInput::make('password')->label('Пароль')->password()->revealable()
                ->rule(Password::default())
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Оставьте пустым, чтобы не менять.' : null),
            CheckboxList::make('roles')->label('Роли')->options(Role::class)->required()->columns(2)->columnSpanFull()
                ->helperText('Без роли вход в панель закрыт.'),
            Toggle::make('is_active')->label('Активен')->default(true)
                ->helperText('Неактивный пользователь не может войти. Удаления пользователей нет — только отключение.'),
        ]);
    }
}
