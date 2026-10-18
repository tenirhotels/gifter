<?php

namespace App\Filament\Resources\Providers\Tables;

use App\Enums\ProviderType;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProvidersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('type')->label('Тип')->badge(),
                TextColumn::make('requisites')->label('Реквизиты')->state(fn ($record) => $record->requisites())->placeholder('—')
                    ->searchable(['legal_name', 'tax_id']),
                TextColumn::make('contact_person')->label('Контакт')->description(fn ($record) => $record->phone)->placeholder('—'),
                TextColumn::make('services_count')->label('Услуг')->counts('services'),
                IconColumn::make('is_active')->label('Активен')->boolean(),
            ])
            ->filters([SelectFilter::make('type')->label('Тип')->options(ProviderType::class)])
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }
}
