<?php

namespace App\Filament\Resources\Packages\Tables;

use App\Models\Package;
use App\Services\PackageSales;
use App\Services\ShopAvailability;
use App\Support\Money;
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

    /**
     * Цена пакета в списке: сумма либо причина, почему её нет (красным).
     *
     * @return array{text: string, ok: bool, tooltip: ?string}
     */
    private static function price(Package $package): array
    {
        static $cache;
        $cache ??= new \WeakMap;

        return $cache[$package] ??= (function () use ($package): array {
            if ($package->is_complimentary) {
                return ['text' => __('Бесплатный'), 'ok' => true, 'tooltip' => null];
            }
            $price = app(ShopAvailability::class)->price($package);
            $note = $price['date']->isFuture() ? __('Цена на :p1 (начало периода пакета)', ['p1' => $price['date']->format('d.m.Y')]) : null;
            if ($price['total'] === null || $price['problems'] !== []) {
                return ['text' => $price['problems'][0] ?? __('Нет цены'), 'ok' => false, 'tooltip' => implode("\n", $price['problems'])];
            }

            return ['text' => Money::format($price['total']), 'ok' => true, 'tooltip' => $note];
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
                TextColumn::make('price')->label('Цена')->alignEnd()->wrap()
                    ->state(fn (Package $record) => self::price($record)['text'])
                    ->color(fn (Package $record) => self::price($record)['ok'] ? null : 'danger')
                    ->icon(fn (Package $record) => self::price($record)['ok'] ? null : 'heroicon-o-exclamation-triangle')
                    ->tooltip(fn (Package $record) => self::price($record)['tooltip']),
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
