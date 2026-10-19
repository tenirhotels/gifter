<?php

namespace App\Filament\Resources\Services\Tables;

use App\Enums\Permission;
use App\Exceptions\DomainRuleViolation;
use App\Models\Service;
use App\Services\ServicePricing;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        $current = function (Service $record, string $field): string {
            try {
                $value = app(ServicePricing::class)->priceOn($record, today())->{$field};

                return $value === null ? 'не указана' : Money::format($value);
            } catch (DomainRuleViolation) {
                return '—';
            }
        };

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'provider', 'pmsService']))
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable()
                    ->description(fn (Service $record) => $record->unit),
                TextColumn::make('category.name')->label('Категория')->sortable()->placeholder('—'),
                TextColumn::make('provider.name')->label('Поставщик')->placeholder('—'),
                TextColumn::make('current_price')->label('Цена сегодня')->state(fn (Service $record) => $current($record, 'price')),
                TextColumn::make('current_cost')->label('Себестоимость')->state(fn (Service $record) => $current($record, 'cost'))
                    ->visible(fn () => auth()->user()?->can(Permission::ViewCosts)),
                IconColumn::make('requires_fulfillment')->label('Подготовка')->boolean(),
                IconColumn::make('requires_scheduling')->label('Время')->boolean()->toggleable(),
                IconColumn::make('requires_confirmation')->label('Подтверждение')->boolean()->toggleable(),
                IconColumn::make('from_pms')->label('Из PMS')->boolean()->state(fn (Service $record) => $record->pmsService !== null)->toggleable(),
                IconColumn::make('is_active')->label('Активна')->boolean(),
            ])
            ->filters([
                SelectFilter::make('category_id')->label('Категория')->relationship('category', 'name'),
                SelectFilter::make('provider_id')->label('Поставщик')->relationship('provider', 'name'),
                TernaryFilter::make('requires_fulfillment')->label('Требует подготовки'),
                TernaryFilter::make('is_active')->label('Активна'),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }
}
