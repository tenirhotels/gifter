<?php

namespace App\Filament\Widgets;

use App\Enums\BatchStatus;
use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Filament\Resources\CertificateBatches\CertificateBatchResource;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Support\RoleQueueWidget;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SalesQueueWidget extends RoleQueueWidget
{
    protected static ?int $sort = 1;

    protected int|array|null $columns = ['default' => 2, 'md' => 3, 'xl' => 6];

    protected static function roles(): array
    {
        return [Role::Sales, Role::SalesManager];
    }

    protected function getHeading(): ?string
    {
        return auth()->user()->hasRole(Role::SalesManager) ? 'Продажи — все сертификаты' : 'Мои продажи';
    }

    protected function getStats(): array
    {
        $q = $this->q();
        $list = fn (array $filters) => CertificateResource::getUrl('index', ['filters' => $filters]);
        $sold = $q->soldThisMonth();

        $stats = [
            $this->counter('Ждут оплаты', $q->awaitingPayment(), $list(['status' => ['values' => ['draft']]]), 'warning', 'выставлен счёт, денег нет'),
            $this->counter('Готовы к выпуску', $q->readyToIssue(), $list(['status' => ['values' => ['draft']]]), 'success', 'оплачены — нажмите «Выпустить»'),
            $this->counter('Без получателя', $q->withoutRecipient(), $list(['no_recipient' => ['isActive' => true]]), 'warning', 'нужен для брони'),
            $this->counter('Истекают ≤ 14 дней', $q->expiringSoon(), $list(['expiring' => ['isActive' => true]]), 'danger', 'напомнить гостю'),
            $this->counter('Черновики', $q->drafts(), $list(['status' => ['values' => ['draft']]]), 'gray'),
            Stat::make('Продано в этом месяце', (clone $sold)->count())
                ->description(Money::format((clone $sold)->sum('total_amount')))
                ->color('gray'),
        ];

        if (auth()->user()->hasRole(Role::SalesManager)) {
            $stats[] = $this->counter('Бесплатные у директора', $q->complimentaryAwaitingApproval(),
                $list(['awaiting_approval' => ['isActive' => true]]), 'info', 'ждут согласования');
            $stats[] = $this->counter('Блоки к выпуску', $q->batches(BatchStatus::Approved),
                CertificateBatchResource::getUrl('index', ['filters' => ['status' => ['value' => 'approved']]]), 'success', 'согласованы директором');
            $stats[] = $this->counter('Возвраты на согласовании', $q->refunds(RefundStatus::Requested),
                RefundResource::getUrl('index', ['filters' => ['status' => ['value' => 'requested']]]), 'info', 'у Финансов');
        }

        return $stats;
    }
}
