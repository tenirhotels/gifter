<?php

namespace App\Filament\Resources\Packages\Tables;

use App\Models\Package;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PackagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Название пакета')->searchable()->sortable(),
                TextColumn::make('kind')->label('Тип')->badge()
                    ->state(fn (Package $record) => $record->is_complimentary ? __('Бесплатный') : __('Продажа'))
                    ->description(fn (Package $record) => $record->is_complimentary ? $record->complimentary_purpose?->getLabel() : null)
                    ->color(fn (Package $record) => $record->is_complimentary ? 'info' : 'gray'),
                TextColumn::make('validity')->label('Срок действия')->state(fn (Package $record): string => $record->validity_unit->format($record->validity_value)),
                TextColumn::make('composition')->label('Состав сертификата')->state(fn (Package $record): string => $record->items
                    ->map(fn ($item) => "{$item->service->localizedName()} × {$item->quantity}")->implode(', '))->wrap(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
                IconColumn::make('sold_online')->label('Онлайн')->boolean()->toggleable(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('items.service'))
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }
}
