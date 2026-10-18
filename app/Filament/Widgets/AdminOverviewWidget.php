<?php

namespace App\Filament\Widgets;

use App\Enums\CertificateStatus;
use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Support\RoleQueueWidget;
use App\Support\Money;

class AdminOverviewWidget extends RoleQueueWidget
{
    protected static ?int $sort = 0;

    protected ?string $heading = 'Обзор';

    protected static function roles(): array
    {
        return [Role::SuperAdmin];
    }

    protected function headingIcon(): string
    {
        return 'heroicon-o-shield-check';
    }

    protected function getStats(): array
    {
        $q = $this->q();
        $list = fn (array $filters) => CertificateResource::getUrl('index', ['filters' => $filters]);
        $sold = $q->soldThisMonth();

        return [
            $this->metric('Продажи за месяц', Money::format((clone $sold)->sum('total_amount')), (clone $sold)->count().' серт.', 'heroicon-o-chart-bar'),
            $this->counter('Активные', $q->status(CertificateStatus::Active), $list(['status' => ['values' => ['active']]]), 'info', 'действуют сейчас', 'heroicon-o-ticket'),
            $this->counter('Черновики', $q->drafts(), $list(['status' => ['values' => ['draft']]]), 'gray', 'не выпущены', 'heroicon-o-pencil-square'),
            $this->counter('Истекают ≤ 14 дней', $q->expiringSoon(), $list(['expiring' => ['isActive' => true]]), 'danger', 'активные и забронированные', 'heroicon-o-exclamation-triangle'),
            $this->counter('Бесплатные у директора', $q->complimentaryAwaitingApproval(), $list(['awaiting_approval' => ['isActive' => true]]), 'info', 'ждут согласования', 'heroicon-o-hand-raised'),
            $this->counter('Оплаты ждут сверки', $q->paymentsToReconcile(), PaymentResource::getUrl('index'), 'warning', 'у Финансов', 'heroicon-o-document-check'),
            $this->counter('Возвраты на согласовании', $q->refunds(RefundStatus::Requested), RefundResource::getUrl('index', ['filters' => ['status' => ['value' => 'requested']]]), 'danger', 'у Финансов', 'heroicon-o-receipt-refund'),
        ];
    }
}
