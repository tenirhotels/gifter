<?php

namespace App\Filament\Support;

use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\Payment;
use App\Services\AdminCorrections;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;

/** Исправления администратора по оплатам — для сертификата и реестра оплат. */
final class PaymentCorrectionActions
{
    /** @return list<Action> */
    public static function make(?\Closure $after = null): array
    {
        $after ??= fn () => null;

        return [
            Action::make('adminReopenPayment')->label('Вернуть в «Ожидает оплаты»')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                ->authorize(Permission::AdminCorrections)
                ->visible(fn (Payment $record) => $record->status !== PaymentStatus::Pending)
                ->modalDescription('Исправление ошибочной отметки оплаты или отмены счёта. Запись попадёт в историю сертификата.')
                ->schema([Textarea::make('reason')->label('Причина')->required()])
                ->action(function (Payment $record, array $data, Action $action) use ($after) {
                    RunsDomainActions::run($action, fn () => app(AdminCorrections::class)->reopenPayment($record, $data['reason']), 'Счёт возвращён');
                    $after();
                }),
            Action::make('adminUnreconcile')->label('Снять сверку')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                ->authorize(Permission::AdminCorrections)
                ->visible(fn (Payment $record) => $record->isReconciled())
                ->schema([Textarea::make('reason')->label('Причина')->required()])
                ->action(function (Payment $record, array $data, Action $action) use ($after) {
                    RunsDomainActions::run($action, fn () => app(AdminCorrections::class)->unreconcile($record, $data['reason']), 'Сверка снята');
                    $after();
                }),
        ];
    }
}
