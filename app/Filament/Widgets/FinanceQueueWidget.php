<?php

namespace App\Filament\Widgets;

use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Filament\Pages\ReservationReviews;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Support\RoleQueueWidget;
use App\Support\Money;

class FinanceQueueWidget extends RoleQueueWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Финансы';

    protected static function roles(): array
    {
        return [Role::Finance];
    }

    protected function headingIcon(): string
    {
        return 'heroicon-o-banknotes';
    }

    protected function getStats(): array
    {
        $q = $this->q();
        $refunds = fn (string $status) => RefundResource::getUrl('index', ['filters' => ['status' => ['value' => $status]]]);
        $received = $q->receivedThisMonth();

        return [
            $this->counter('Ждут сверки', $q->paymentsToReconcile(), PaymentResource::getUrl('index'), 'warning', 'отмечены продажами', 'heroicon-o-document-check'),
            $this->counter('Перечисления на р/с', $q->bankTransfersAwaiting(),
                PaymentResource::getUrl('index', ['filters' => ['method' => ['value' => 'bank_transfer'], 'status' => ['value' => 'pending']]]), 'warning', 'проверить выписку', 'heroicon-o-building-library'),
            $this->counter('Брони на проверке', $q->reservationsUnderReview(), ReservationReviews::getUrl(), 'warning', 'имя не совпало или поздняя бронь', 'heroicon-o-shield-check'),
            $this->counter('Возвраты на согласовании', $q->refunds(RefundStatus::Requested), $refunds('requested'), 'danger', 'нужно ваше решение', 'heroicon-o-receipt-refund'),
            $this->counter('Возвраты ждут выплаты', $q->refunds(RefundStatus::Approved), $refunds('approved'), 'warning', 'отметить выплату', 'heroicon-o-arrow-uturn-left'),
            $this->metric('Поступило за месяц', Money::format((clone $received)->sum('amount')), (clone $received)->count().' оплат', 'heroicon-o-banknotes'),
        ];
    }
}
