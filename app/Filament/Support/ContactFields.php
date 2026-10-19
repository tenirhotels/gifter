<?php

namespace App\Filament\Support;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Support\ContactMask;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Контакты людей скрыты везде; полностью — по кнопке «Показать»,
 * каждое открытие пишется в журнал аудита (кто, когда, чьи контакты).
 */
final class ContactFields
{
    /** @return list<Component> */
    public static function nameInputs(): array
    {
        return [
            TextInput::make('last_name')->label('Фамилия')->required()->maxLength(100),
            TextInput::make('first_name')->label('Имя')->required()->maxLength(100),
            TextInput::make('middle_name')->label('Отчество')->maxLength(100),
        ];
    }

    /** @return list<Component> */
    public static function contactInputs(): array
    {
        return [
            TextInput::make('phone')->label('Телефон')->tel()->maxLength(32),
            TextInput::make('email')->label('Email')->email()->maxLength(255),
        ];
    }

    /** @return list<TextColumn> */
    public static function maskedColumns(): array
    {
        return [
            // Контакты в базе зашифрованы: поиск — по полному номеру или email (через хеш, см. HasEncryptedContacts).
            TextColumn::make('phone')->label('Телефон')->formatStateUsing(fn ($state) => ContactMask::phone($state))
                ->searchable(query: fn (Builder $query, string $search) => $query->whereContact($search)),
            TextColumn::make('email')->label('Email')->formatStateUsing(fn ($state) => ContactMask::email($state))->toggleable(),
        ];
    }

    /** Действие «Показать контакты» для записи заказчика/получателя или связанной с ней. */
    public static function revealAction(string $name = 'revealContacts', ?\Closure $person = null, string $label = 'Показать контакты'): Action
    {
        $person ??= fn (Model $record) => $record;

        return Action::make($name)->label($label)->icon('heroicon-o-eye')->color('gray')
            ->authorize(Permission::RevealContacts)
            ->visible(fn (Model $record) => $person($record) !== null)
            ->modalHeading(fn (Model $record) => $person($record)?->full_name)
            ->modalDescription('Открытие контактов записано в журнал аудита.')
            ->mountUsing(fn (Model $record) => self::logReveal($person($record)))
            ->schema(fn (Model $record) => [
                TextEntry::make('phone')->label('Телефон')->state($person($record)?->phone)->placeholder('—')->copyable(),
                TextEntry::make('email')->label('Email')->state($person($record)?->email)->placeholder('—')->copyable(),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть');
    }

    public static function logReveal(?Model $person): void
    {
        if ($person) {
            AuditLog::record('contacts_revealed', $person, null, null);
        }
    }
}
