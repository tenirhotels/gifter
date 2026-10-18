<?php

namespace App\Filament\Widgets;

use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Support\RoleQueueWidget;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FinanceQueueWidget extends RoleQueueWidget
{
    protected static ?int $sort = 4;

    protected int|array|null $columns = ['default' => 2, 'md' => 3, 'xl' => 6];

    protected ?string $heading = 'Финансы';

    protected static function roles(): array
    {
        return [Role::Finance];
    }

    protected function getStats(): array
    {
        $q = $this->q();
        $refunds = fn (string $status) => RefundResource::getUrl('index', ['filters' => ['status' => ['value' => $status]]]);
        $received = $q->receivedThisMonth();

        return [
            $this->counter('Ждут сверки', $q->paymentsToReconcile(), PaymentResource::getUrl('index'), 'warning', 'отмечены продажами'),
            $this->counter('Перечисления на р/с', $q->bankTransfersAwaiting(),
                PaymentResource::getUrl('index', ['filters' => ['method' => ['value' => 'bank_transfer'], 'status' => ['value' => 'pending']]]), 'warning', 'проверить выписку'),
            $this->counter('Возвраты на согласовании', $q->refunds(RefundStatus::Requested), $refunds('requested'), 'danger', 'нужно ваше решение'),
            $this->counter('Возвраты ждут выплаты', $q->refunds(RefundStatus::Approved), $refunds('approved'), 'warning'),
            Stat::make('Поступило за месяц', Money::format((clone $received)->sum('amount')))
                ->description((clone $received)->count().' оплат')
                ->color('gray'),
        ];
    }
}
