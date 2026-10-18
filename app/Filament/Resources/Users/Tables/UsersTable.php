<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Имя')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
                TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y')->sortable(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }
}
