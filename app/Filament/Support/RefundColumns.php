<?php

namespace App\Filament\Support;

use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Колонки возвратов — общие для сертификата и реестра. */
final class RefundColumns
{
    public static function apply(Table $table, bool $withCertificate = false): Table
    {
        return $table->columns(array_values(array_filter([
            $withCertificate ? TextColumn::make('certificate.number')->label('Сертификат')->searchable() : null,
            TextColumn::make('status')->label('Статус')->badge(),
            TextColumn::make('amount')->label('Сумма')->formatStateUsing(fn ($state) => Money::format($state)),
            TextColumn::make('reason')->label('Причина')->wrap(),
            TextColumn::make('requester.name')->label('Запросил')->description(fn ($record) => $record->created_at?->format('d.m.Y H:i')),
            TextColumn::make('reviewer.name')->label('Решение')->placeholder('—')
                ->description(fn ($record) => trim(($record->reviewed_at?->format('d.m.Y H:i') ?? '').' '.($record->decision_comment ?? '')) ?: null),
            TextColumn::make('paid_at')->label('Выплачено')->dateTime('d.m.Y H:i')->placeholder('—')
                ->description(fn ($record) => $record->payout_reference),
        ])));
    }
}
