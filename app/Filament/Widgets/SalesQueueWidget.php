<?php

namespace App\Filament\Widgets;

use App\Enums\FulfillmentStatus;
use App\Enums\OnlineOrderStatus;
use App\Enums\Permission;
use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Filament\Resources\CertificateBatches\CertificateBatchResource;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\Fulfillments\ServiceFulfillmentResource;
use App\Filament\Resources\OnlineOrders\OnlineOrderResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Support\RoleQueueWidget;
use App\Models\OnlineOrder;
use App\Support\Money;

class SalesQueueWidget extends RoleQueueWidget
{
    protected static ?int $sort = 1;

    protected static function roles(): array
    {
        return [Role::Sales, Role::SalesManager];
    }

    protected function getHeading(): ?string
    {
        return auth()->user()->hasRole(Role::SalesManager) ? __('Продажи — все сертификаты') : __('Мои продажи');
    }

    protected function headingIcon(): string
    {
        return 'heroicon-o-shopping-bag';
    }

    protected function getStats(): array
    {
        $q = $this->q();
        $list = fn (array $filters) => CertificateResource::getUrl('index', ['filters' => $filters]);
        $sold = $q->soldThisMonth();

        $stats = [
            $this->counter('Ждут оплаты', $q->awaitingPayment(), $list(['status' => ['values' => ['draft']]]), 'warning', 'выставлен счёт, денег нет', 'heroicon-o-clock'),
            $this->counter('Готовы к выпуску', $q->readyToIssue(), $list(['status' => ['values' => ['draft']]]), 'success', 'оплачены — нажмите «Выпустить»', 'heroicon-o-check-badge'),
            $this->counter('Без получателя', $q->withoutRecipient(), $list(['no_recipient' => ['isActive' => true]]), 'warning', 'нужен для брони', 'heroicon-o-user-plus'),
            $this->counter('Истекают за 14 дней', $q->expiringSoon(), $list(['expiring' => ['isActive' => true]]), 'danger', 'напомнить гостю', 'heroicon-o-exclamation-triangle'),
            $this->counter('Черновики', $q->drafts(), $list(['status' => ['values' => ['draft']]]), 'gray', 'не выпущены', 'heroicon-o-pencil-square'),
            $this->metric('Продано в этом месяце', (clone $sold)->count(), Money::format((clone $sold)->sum('total_amount')), 'heroicon-o-chart-bar'),
        ];

        if (auth()->user()->can(Permission::ProcessOnlineOrders)) {
            array_unshift($stats, $this->counter('Заявки с сайта', OnlineOrder::query()->where('status', OnlineOrderStatus::New),
                OnlineOrderResource::getUrl('index'), 'warning', 'ждут оформления', 'heroicon-o-globe-alt'));
        }

        $batches = fn (string $filter) => CertificateBatchResource::getUrl('index', ['filters' => [$filter => ['isActive' => true]]]);
        if ($q->purchasesReadyToIssue()->exists()) {
            $stats[] = $this->counter('Платные группы к выпуску', $q->purchasesReadyToIssue(), $batches('ready'), 'success', 'оплачены полностью', 'heroicon-o-rectangle-stack');
        }

        if (auth()->user()->hasRole(Role::SalesManager)) {
            if ($q->freeIssuesReadyToIssue()->exists()) {
                $stats[] = $this->counter('Бесплатные группы к выпуску', $q->freeIssuesReadyToIssue(), $batches('ready'), 'success', 'согласованы директором', 'heroicon-o-gift');
            }
            $stats[] = $this->counter('Заявки ждут отправки', $q->fulfillments()->where('status', FulfillmentStatus::Pending),
                ServiceFulfillmentResource::getUrl('index', ['filters' => ['status' => ['values' => ['pending']]]]), 'warning', 'исполнение услуг', 'heroicon-o-paper-airplane');
            $stats[] = $this->counter('Бесплатные у директора', $q->complimentaryAwaitingApproval(),
                $list(['awaiting_approval' => ['isActive' => true]]), 'info', 'ждут согласования', 'heroicon-o-hand-raised');
            $stats[] = $this->counter('Возвраты на согласовании', $q->refunds(RefundStatus::Requested),
                RefundResource::getUrl('index', ['filters' => ['status' => ['value' => 'requested']]]), 'info', 'у Финансов', 'heroicon-o-receipt-refund');
        }

        return $stats;
    }
}
