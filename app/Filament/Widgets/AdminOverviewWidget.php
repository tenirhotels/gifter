<?php

namespace App\Filament\Widgets;

use App\Enums\CertificateStatus;
use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Filament\Pages\Security;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\RoleQueueWidget;
use App\Models\AuditLog;
use App\Models\ServicePrice;
use App\Services\SecurityAudit;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget\Stat;

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
            $this->counter('Лимит контактов', AuditLog::query()->where('event', 'contacts_reveal_limit')->where('created_at', '>=', today()), AuditLogResource::getUrl('index'), 'warning', 'попыток превысить дневной лимит сегодня', 'heroicon-o-eye'),
            $this->counter('Цены ждут подтверждения', ServicePrice::query()->whereNull('approved_at'), ServiceResource::getUrl('index'), 'warning', 'добавил директор — подтвердите', 'heroicon-o-currency-dollar'),
            $this->problemsTile(),
        ];
    }

    private function problemsTile(): Stat
    {
        $problems = Security::problems();
        $checked = app(SecurityAudit::class)->lastResult() !== null;

        return $this->tile(Stat::make('Безопасность', $checked ? $problems : '—')
            ->description($checked ? ($problems ? 'ошибок в настройках' : 'критичных ошибок нет') : 'проверка не запускалась')
            ->url(Security::getUrl()), 'heroicon-o-lock-closed', $problems ? 'danger' : 'zero', $problems > 0);
    }
}
