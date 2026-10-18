<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Когда')->dateTime('d.m.Y H:i:s')->sortable(),
                TextColumn::make('user.name')->label('Кто')->placeholder('система'),
                TextColumn::make('event')->label('Событие')->badge()->formatStateUsing(fn (string $state) => match ($state) {
                    'created' => 'создание', 'updated' => 'изменение', 'deleted' => 'удаление', default => $state,
                }),
                TextColumn::make('auditable_type')->label('Объект')->formatStateUsing(fn (string $state, AuditLog $record) => class_basename($state).' #'.$record->auditable_id),
                TextColumn::make('after')->label('Изменения')->state(fn (AuditLog $record) => collect($record->after ?? $record->before ?? [])
                    ->except(['created_at', 'updated_at', 'id'])->map(fn ($v, $k) => $k.': '.(is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_UNICODE)))
                    ->implode('; '))->limit(120)->wrap(),
            ])
            ->filters([
                SelectFilter::make('auditable_type')->label('Объект')->options(fn () => AuditLog::query()->distinct()->pluck('auditable_type')
                    ->mapWithKeys(fn ($type) => [$type => class_basename($type)])->all()),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc');
    }
}
