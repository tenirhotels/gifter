<?php

namespace App\Filament\Support;

use App\Enums\ValidityMode;
use App\Enums\ValidityUnit;
use App\Exceptions\DomainRuleViolation;
use App\Models\Package;
use App\Support\ValidityChoice;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;

/**
 * Выбор срока бесплатного сертификата при выпуске (мастер и блок).
 * Поля пишутся в `validity.*`; расчёт и проверки — в ValidityChoice.
 */
final class ValidityFields
{
    /** @param  string  $packageField путь к полю пакета относительно формы */
    public static function make(string $packageField = 'package_id'): Grid
    {
        $mode = fn (Get $get): ValidityMode => ValidityMode::tryFrom((string) ($get('mode') instanceof ValidityMode ? $get('mode')->value : $get('mode'))) ?? ValidityMode::Package;

        return Grid::make(4)->statePath('validity')->columnSpanFull()->schema([
            ToggleButtons::make('mode')->label('Срок действия')->options(ValidityMode::class)->inline()
                ->default(ValidityMode::Package->value)->live()->columnSpanFull(),
            DatePicker::make('from')->label('Действует с')->default(today())->live()
                ->visible(fn (Get $get) => $mode($get) !== ValidityMode::Package)
                ->helperText('Например, дата мероприятия или начала акции.'),
            TextInput::make('value')->label('Длительность')->integer()->minValue(1)->maxValue(1000)->default(1)->live(onBlur: true)
                ->visible(fn (Get $get) => $mode($get) === ValidityMode::Duration)->required(fn (Get $get) => $mode($get) === ValidityMode::Duration),
            Select::make('unit')->label('Единица')->options(ValidityUnit::class)->default(ValidityUnit::Months->value)->live()
                ->visible(fn (Get $get) => $mode($get) === ValidityMode::Duration)->required(fn (Get $get) => $mode($get) === ValidityMode::Duration),
            DatePicker::make('until')->label('Действует по')->live()
                ->visible(fn (Get $get) => $mode($get) === ValidityMode::UntilDate)->required(fn (Get $get) => $mode($get) === ValidityMode::UntilDate),
            Placeholder::make('preview')->label('Итог')->columnSpanFull()
                ->content(fn (Get $get) => self::preview(Package::find($get("../{$packageField}")), $get(), $get('../sold_at'))),
        ]);
    }

    /** Предпросмотр срока тем же расчётом, что и при сохранении. */
    public static function preview(?Package $package, ?array $validity, mixed $soldAt = null): string
    {
        if (! $package) {
            return 'по выбранному пакету';
        }
        $choice = ValidityChoice::fromArray($validity);
        try {
            [$from, $until] = $choice->resolve($package, Carbon::parse($soldAt ?: today()));
        } catch (DomainRuleViolation $e) {
            return $e->getMessage();
        }

        return $from->format('d.m.Y').' — '.$until->format('d.m.Y').($choice->isCustom() ? ' (задан вручную)' : ' (по пакету)');
    }
}
