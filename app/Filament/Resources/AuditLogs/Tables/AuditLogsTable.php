<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use App\Support\AuditPresenter;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Когда')->dateTime('d.m.Y H:i:s')->sortable(),
                TextColumn::make('user.name')->label('Кто')->placeholder(__('система')),
                TextColumn::make('event')->label('Событие')->badge()->formatStateUsing(fn (string $state) => AuditPresenter::event($state))
                    ->color(fn (string $state) => AuditPresenter::eventColor($state)),
                TextColumn::make('auditable_type')->label('Объект')->state(fn (AuditLog $record) => AuditPresenter::subject($record))->wrap(),
                TextColumn::make('after')->label('Что изменилось')->state(fn (AuditLog $record) => AuditPresenter::summary($record) ?: '—')->limit(180)->wrap(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'auditable']))
            ->filters([
                SelectFilter::make('event')->label('Событие')->options(fn () => AuditLog::query()->distinct()->pluck('event')
                    ->mapWithKeys(fn ($event) => [$event => AuditPresenter::event($event)])->sort()->all()),
                SelectFilter::make('auditable_type')->label('Объект')->options(fn () => AuditLog::query()->distinct()->pluck('auditable_type')
                    ->mapWithKeys(fn ($type) => [$type => AuditPresenter::subjectType($type)])->sort()->all()),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc');
    }
}
