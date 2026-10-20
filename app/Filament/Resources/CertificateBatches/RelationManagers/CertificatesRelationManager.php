<?php

namespace App\Filament\Resources\CertificateBatches\RelationManagers;

use App\Filament\Resources\Certificates\CertificateResource;
use App\Models\Certificate;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

/** Сертификаты группы; получателя указывают на странице каждого сертификата. */
class CertificatesRelationManager extends RelationManager
{
    protected static string $relationship = 'certificates';

    protected static ?string $title = 'Сертификаты';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('recipient'))
            ->recordUrl(fn (Certificate $record) => CertificateResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('number')->label('Номер')->searchable(),
                TextColumn::make('status')->label('Статус')->badge(),
                TextColumn::make('recipient.full_name')->label('Получатель')->placeholder(__('не указан')),
                TextColumn::make('valid_until')->label('Действует по')->date('d.m.Y'),
            ])
            ->filters([Filter::make('no_recipient')->label('Без получателя')->query(fn ($query) => $query->whereNull('recipient_id'))])
            ->defaultSort('id');
    }
}
