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
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminOverviewWidget extends RoleQueueWidget
{
    protected static ?int $sort = 0;

    protected int|array|null $columns = ['default' => 2, 'md' => 3, 'xl' => 6];

    protected ?string $heading = 'Обзор';

    protected static function roles(): array
    {
        return [Role::SuperAdmin];
    }

    protected function getStats(): array
    {
        $q = $this->q();
        $list = fn (array $filters) => CertificateResource::getUrl('index', ['filters' => $filters]);
        $sold = $q->soldThisMonth();

        return [
            Stat::make('Продажи за месяц', Money::format((clone $sold)->sum('total_amount')))->description((clone $sold)->count().' серт.')->color('gray'),
            $this->counter('Активные', $q->status(CertificateStatus::Active), $list(['status' => ['values' => ['active']]]), 'gray'),
            $this->counter('Черновики', $q->drafts(), $list(['status' => ['values' => ['draft']]]), 'gray'),
            $this->counter('Истекают ≤ 14 дней', $q->expiringSoon(), $list(['expiring' => ['isActive' => true]]), 'danger'),
            $this->counter('Бесплатные у директора', $q->complimentaryAwaitingApproval(), $list(['awaiting_approval' => ['isActive' => true]]), 'info'),
            $this->counter('Оплаты ждут сверки', $q->paymentsToReconcile(), PaymentResource::getUrl('index'), 'warning'),
            $this->counter('Возвраты на согласовании', $q->refunds(RefundStatus::Requested), RefundResource::getUrl('index', ['filters' => ['status' => ['value' => 'requested']]]), 'danger'),
        ];
    }
}
