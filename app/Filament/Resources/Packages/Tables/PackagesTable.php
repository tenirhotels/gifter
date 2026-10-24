<?php

namespace App\Filament\Resources\Packages\Tables;

use App\Models\Package;
use App\Services\PackageSales;
use App\Services\ShopAvailability;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PackagesTable
{
    /**
     * Одна иконка вместо «Онлайн» и «На сайте»: shown — показывается, hidden — онлайн включён, но на сайте не видно (причины в подсказке), offline — онлайн выключен.
     *
     * @return array{state: string, tooltip: string}
     */
    private static function shop(Package $package): array
    {
        static $cache;
        $cache ??= new \WeakMap;

        return $cache[$package] ??= (function () use ($package): array {
            if (! $package->sold_online || $package->is_complimentary) {
                return ['state' => 'offline', 'tooltip' => $package->is_complimentary ? __('Бесплатные пакеты на сайте не продаются') : __('Не включено «Продаётся онлайн»')];
            }
            $problems = app(ShopAvailability::class)->problems($package);

            return $problems === []
                ? ['state' => 'shown', 'tooltip' => __('Показывается на главной странице сайта')]
                : ['state' => 'hidden', 'tooltip' => __('Не показывается').': '.implode('; ', $problems)];
        })();
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Название пакета')->searchable()->sortable()->state(fn ($record) => $record->localizedName()),
                TextColumn::make('kind')->label('Тип')->badge()
                    ->state(fn (Package $record) => $record->is_complimentary ? __('Бесплатный') : __('Продажа'))
                    ->description(fn (Package $record) => $record->is_complimentary ? $record->complimentary_purpose?->getLabel() : null)
                    ->color(fn (Package $record) => $record->is_complimentary ? 'info' : 'gray'),
                TextColumn::make('validity')->label('Срок действия')->state(fn (Package $record): string => $record->isFixedPeriod() ? $record->validityText('ru') : $record->validity_unit->format($record->validity_value)),
                TextColumn::make('composition')->label('Состав сертификата')->state(fn (Package $record): string => $record->items
                    ->map(fn ($item) => "{$item->service->localizedName()} × {$item->quantity}")->implode(', '))->wrap(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
                IconColumn::make('shop')->label('На сайте')
                    ->state(fn (Package $record) => self::shop($record)['state'])
                    ->icon(fn (string $state) => match ($state) {
                        'shown' => 'heroicon-o-check-circle',
                        'hidden' => 'heroicon-o-exclamation-triangle',
                        default => 'heroicon-o-minus-circle',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'shown' => 'success',
                        'hidden' => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(fn (Package $record) => self::shop($record)['tooltip']),
                TextColumn::make('sales')->label('Продажа')->wrap()->toggleable()
                    ->state(fn (Package $record) => app(PackageSales::class)->label($record))
                    ->color(fn (Package $record) => app(PackageSales::class)->state($record) === PackageSales::OPEN ? null : 'warning'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('items.service'))
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }
}
