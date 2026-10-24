<?php

namespace App\Filament\Support;

use App\Enums\Permission;
use App\Services\ContactRevealGuard;
use App\Support\ContactMask;
use App\Support\PhoneNumber;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
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
            self::phoneInput(),
            TextInput::make('email')->label('Email')->email()->maxLength(255),
            self::phoneInput('phone2')->label('Второй телефон')->helperText(__('Необязательно. Например, рабочий или зарубежный номер.')),
        ];
    }

    /** Телефон в международном формате; хранится без пробелов (+77011234567). */
    public static function phoneInput(string $name = 'phone'): TextInput
    {
        return TextInput::make($name)->label('Телефон')->tel()->telRegex('/^[\d\s()+\-.]+$/')->maxLength(32)->placeholder('+7 701 123 45 67')
            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                if (! PhoneNumber::isValid($value)) {
                    $fail(__(PhoneNumber::HINT).'.');
                }
            })
            ->dehydrateStateUsing(fn (?string $state) => PhoneNumber::normalize($state));
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
            ->modalDescription(__('Просмотр контактов фиксируется: сохраняется, кто и когда их открыл.'))
            ->mountUsing(function (Model $record, Action $action) use ($person) {
                if (! self::logReveal($person($record))) {
                    Notification::make()->danger()->title(self::limitMessage())->send();
                    $action->halt();
                }
            })
            ->schema(fn (Model $record) => [
                TextEntry::make('phone')->label('Телефон')->state(PhoneNumber::format($person($record)?->phone))->placeholder('—')->copyable(),
                TextEntry::make('phone2')->label('Второй телефон')->state(PhoneNumber::format($person($record)?->phone2))->placeholder('—')->copyable()
                    ->visible(fn () => (bool) $person($record)?->hasSecondPhone()),
                TextEntry::make('email')->label('Email')->state($person($record)?->email)->placeholder('—')->copyable(),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть');
    }

    /** Записать открытие контактов; false — дневной лимит исчерпан. */
    public static function logReveal(?Model $person): bool
    {
        return $person === null || app(ContactRevealGuard::class)->reveal($person);
    }

    public static function limitMessage(): string
    {
        return __('Дневной лимит открытия контактов исчерпан (:p1 клиентов). Попытка записана в журнал; нужен доступ — обратитесь к администратору.', ['p1' => config('gifter.security.reveal_daily_limit')]);
    }
}
