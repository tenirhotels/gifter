<?php

namespace App\Filament\Resources\ServiceCategories\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServiceCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('services_count')->label('Услуг')->counts('services'),
                TextColumn::make('position')->label('Порядок')->sortable(),
                IconColumn::make('is_active')->label('Активна')->boolean(),
            ])
            ->recordActions([EditAction::make()])
            ->reorderable('position')
            ->defaultSort('position');
    }
}
