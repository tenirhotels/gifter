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
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('validity')->label('Срок')->state(fn (Package $record): string => $record->validity_unit->format($record->validity_value)),
                TextColumn::make('composition')->label('Базовый состав')->state(fn (Package $record): string => $record->items
                    ->map(fn ($item) => "{$item->service->name} × {$item->quantity}")->implode(', '))->wrap(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('items.service'))
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }
}
