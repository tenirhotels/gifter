<?php

namespace App\Filament\Resources\Providers\Schemas;

use App\Enums\LegalForm;
use App\Enums\ProviderType;
use App\Filament\Support\ContactFields;
use App\Support\KazakhstanBin;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProviderForm
{
    /** @return array<int, Component> */
    public static function fields(): array
    {
        $external = fn (Get $get): bool => ($get('type') instanceof ProviderType ? $get('type') : ProviderType::tryFrom((string) $get('type'))) === ProviderType::External;

        return [
            TextInput::make('name')->label('Название')->required()->maxLength(255),
            Select::make('type')->label('Тип')->options(ProviderType::class)->required()->live(),
            Grid::make(3)->columnSpanFull()->visible($external)->schema([
                Select::make('legal_form')->label('Правовая форма')->options(LegalForm::class)->required($external)->live(),
                TextInput::make('legal_name')->label('Юридическое наименование')->required($external)->maxLength(255)
                    ->placeholder(__('как в документах, без «ТОО»')),
                TextInput::make('tax_id')->required($external)->length(12)
                    ->label(fn (Get $get) => self::legalForm($get('legal_form'))?->taxIdLabel() ?? 'ИИН / БИН')
                    ->rule(fn () => function (string $attribute, $value, Closure $fail) {
                        if (filled($value) && ! KazakhstanBin::isValid($value)) {
                            $fail('Номер введён с ошибкой: проверьте 12 цифр.');
                        }
                    }),
            ]),
            TextInput::make('contact_person')->label('Контактное лицо')->maxLength(255),
            ContactFields::phoneInput(),
            TextInput::make('email')->label('Email')->email()->maxLength(255),
            Toggle::make('is_active')->label('Активен')->default(true),
            Textarea::make('notes')->label('Примечание (условия, как заказывать)')->columnSpanFull(),
        ];
    }

    private static function legalForm(mixed $value): ?LegalForm
    {
        return $value instanceof LegalForm ? $value : LegalForm::tryFrom((string) $value);
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::fields());
    }
}
