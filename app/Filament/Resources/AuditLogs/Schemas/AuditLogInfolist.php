<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')->label('Когда')->dateTime('d.m.Y H:i:s'),
            TextEntry::make('user.name')->label('Кто')->placeholder(__('система')),
            TextEntry::make('event')->label('Событие'),
            TextEntry::make('auditable_type')->label('Объект')->formatStateUsing(fn ($state, $record) => class_basename($state).' #'.$record->auditable_id),
            TextEntry::make('ip_address')->label('IP')->placeholder('—'),
            TextEntry::make('before_json')->label('До')->columnSpanFull()->placeholder('—')
                ->state(fn ($record) => $record->before ? json_encode($record->before, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : null)
                ->fontFamily('mono'),
            TextEntry::make('after_json')->label('После')->columnSpanFull()->placeholder('—')
                ->state(fn ($record) => $record->after ? json_encode($record->after, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : null)
                ->fontFamily('mono'),
        ]);
    }
}
