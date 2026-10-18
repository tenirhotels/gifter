<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Enums\CustomerType;
use App\Filament\Support\ContactFields;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')->label('ФИО')->searchable(['last_name', 'first_name', 'middle_name'])->sortable(['last_name']),
                TextColumn::make('type')->label('Тип')->badge(),
                TextColumn::make('company')->label('Компания')->description(fn ($record) => $record->bin ? "БИН {$record->bin}" : null)
                    ->searchable(['company', 'bin'])->placeholder('—'),
                ...ContactFields::maskedColumns(),
                TextColumn::make('certificates_count')->label('Сертификатов')->counts('certificates')->sortable(),
            ])
            ->filters([SelectFilter::make('type')->label('Тип')->options(CustomerType::class)])
            ->recordActions([ContactFields::revealAction(label: 'Контакты'), EditAction::make()])
            ->defaultSort('last_name');
    }
}
