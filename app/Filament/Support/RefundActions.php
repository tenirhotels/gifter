<?php

namespace App\Filament\Support;

use App\Enums\Permission;
use App\Enums\RefundStatus;
use App\Models\Refund;
use App\Services\RefundManager;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Carbon;

/** Действия Финансов по возврату — общие для сертификата и реестра возвратов. */
final class RefundActions
{
    /** @return list<Action> */
    public static function make(?\Closure $after = null): array
    {
        $after ??= fn () => null;

        return [
            Action::make('approveRefund')->label('Согласовать')->icon('heroicon-o-check')->color('success')
                ->authorize(Permission::ApproveRefunds)
                ->visible(fn (Refund $record) => $record->status === RefundStatus::Requested)
                ->modalDescription('Сертификат будет аннулирован. Выплату отметите отдельно, когда деньги уйдут клиенту.')
                ->schema([Textarea::make('comment')->label('Комментарий')])
                ->action(function (Refund $record, array $data, Action $action) use ($after) {
                    RunsDomainActions::run($action, fn () => app(RefundManager::class)->approve($record, $data['comment'] ?? null), 'Возврат согласован, сертификат аннулирован');
                    $after();
                }),
            Action::make('rejectRefund')->label('Отклонить')->icon('heroicon-o-x-mark')->color('danger')
                ->authorize(Permission::ApproveRefunds)
                ->visible(fn (Refund $record) => $record->status === RefundStatus::Requested)
                ->schema([Textarea::make('comment')->label('Причина отказа')->required()])
                ->action(function (Refund $record, array $data, Action $action) use ($after) {
                    RunsDomainActions::run($action, fn () => app(RefundManager::class)->reject($record, $data['comment']), 'Запрос отклонён');
                    $after();
                }),
            Action::make('payRefund')->label('Деньги возвращены')->icon('heroicon-o-banknotes')
                ->authorize(Permission::ApproveRefunds)
                ->visible(fn (Refund $record) => $record->status === RefundStatus::Approved)
                ->schema([
                    DateTimePicker::make('paid_at')->label('Дата выплаты')->default(now())->maxDate(now())->required(),
                    TextInput::make('reference')->label('№ платёжного документа')->maxLength(255),
                ])
                ->action(function (Refund $record, array $data, Action $action) use ($after) {
                    RunsDomainActions::run($action, fn () => app(RefundManager::class)
                        ->markPaid($record, Carbon::parse($data['paid_at']), $data['reference'] ?? null), 'Выплата отмечена');
                    $after();
                }),
        ];
    }
}
