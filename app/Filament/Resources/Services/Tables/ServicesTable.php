<?php

namespace App\Filament\Resources\Services\Tables;

use App\Exceptions\DomainRuleViolation;
use App\Models\Service;
use App\Services\ServicePricing;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('unit')->label('Единица'),
                TextColumn::make('current_price')->label('Цена сегодня')->state(function (Service $record): string {
                    try {
                        return Money::format(app(ServicePricing::class)->priceOn($record, today())->price);
                    } catch (DomainRuleViolation) {
                        return '—';
                    }
                }),
                IconColumn::make('is_active')->label('Активна')->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label('Активна')])
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }
}
