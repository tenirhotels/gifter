<?php

namespace App\Filament\Resources\Recipients\Tables;

use App\Filament\Support\ContactFields;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RecipientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')->label('ФИО')->searchable(['last_name', 'first_name', 'middle_name'])->sortable(['last_name']),
                ...ContactFields::maskedColumns(),
                TextColumn::make('certificates_count')->label('Сертификатов')->counts('certificates')->sortable(),
            ])
            ->recordActions([ContactFields::revealAction(label: 'Контакты'), EditAction::make()])
            ->defaultSort('last_name');
    }
}
