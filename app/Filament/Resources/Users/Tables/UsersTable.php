<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Permission;
use App\Filament\Support\RunsDomainActions;
use App\Models\User;
use App\Services\UserSecurity;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Имя')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('roles')->label('Роли')->badge(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
                IconColumn::make('two_factor')->label('2FA')->boolean()
                    ->state(fn (User $record): bool => $record->hasTwoFactor())
                    ->tooltip(fn (User $record): string => $record->hasTwoFactor() ? __('Двухфакторный вход настроен') : __('Настроит при следующем входе')),
                TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y')->sortable(),
            ])
            ->recordActions([EditAction::make(), self::resetTwoFactorAction()])
            ->defaultSort('name');
    }

    public static function resetTwoFactorAction(): Action
    {
        return Action::make('resetTwoFactor')->label('Сбросить 2FA')->icon('heroicon-o-device-phone-mobile')->color('danger')
            ->authorize(Permission::ManageUsers)
            ->visible(fn (User $record): bool => $record->hasTwoFactor() && ! $record->is(auth()->user()))
            ->modalHeading(fn (User $record): string => __('Сбросить двухфакторный вход: :p1', ['p1' => $record->name]))
            ->modalDescription(__('Например, сотрудник потерял телефон. При следующем входе он настроит приложение заново. Сброс записывается в журнал аудита.'))
            ->schema([Textarea::make('reason')->label('Причина')->required()->maxLength(500)])
            ->modalSubmitActionLabel(__('Сбросить'))
            ->action(fn (User $record, array $data, Action $action) => RunsDomainActions::run($action,
                fn () => app(UserSecurity::class)->resetTwoFactor($record, $data['reason']), __('Двухфакторный вход сброшен')));
    }
}
