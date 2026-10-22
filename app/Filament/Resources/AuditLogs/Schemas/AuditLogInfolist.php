<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use App\Models\AuditLog;
use App\Support\AuditPresenter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')->label('Когда')->dateTime('d.m.Y H:i:s'),
            TextEntry::make('user.name')->label('Кто')->placeholder(__('система')),
            TextEntry::make('event')->label('Событие')->badge()->formatStateUsing(fn (string $state) => AuditPresenter::event($state))
                ->color(fn (string $state) => AuditPresenter::eventColor($state)),
            TextEntry::make('auditable_type')->label('Объект')->state(fn (AuditLog $record) => AuditPresenter::subject($record)),
            TextEntry::make('ip_address')->label('IP')->placeholder('—'),
            TextEntry::make('changes')->label('Что изменилось')->columnSpanFull()->html()->placeholder('—')
                ->state(fn (AuditLog $record) => self::table($record)),
        ]);
    }

    /** Таблица «поле — было — стало»; все значения экранируются. */
    private static function table(AuditLog $record): ?HtmlString
    {
        $rows = AuditPresenter::rows($record);
        if ($rows === []) {
            return null;
        }
        $created = $record->event === 'created';
        $html = '<table class="gf-report"><thead><tr><th>'.e(__('Поле')).'</th>'
            .($created ? '' : '<th>'.e(__('Было')).'</th>').'<th>'.e($created ? __('Значение') : __('Стало')).'</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>'.e($row['label']).'</td>'.($created ? '' : '<td>'.e($row['before'] ?? '—').'</td>').'<td>'.e($row['after'] ?? '—').'</td></tr>';
        }

        return new HtmlString($html.'</tbody></table>');
    }
}
