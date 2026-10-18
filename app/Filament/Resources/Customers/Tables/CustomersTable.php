<?php

namespace App\Filament\Resources\Customers\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')->label('ФИО')->searchable()->sortable(),
                TextColumn::make('phone')->label('Телефон')->searchable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('company')->label('Компания')->searchable()->toggleable(),
                TextColumn::make('certificates_count')->label('Сертификатов')->counts('certificates')->sortable(),
                TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('full_name');
    }
}
