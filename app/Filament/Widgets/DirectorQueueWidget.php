<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Filament\Pages\Commissions;
use App\Filament\Pages\ReservationExceptions;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\RoleQueueWidget;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\ReservationException;
use App\Models\ServicePrice;
use App\Services\CommissionManager;
use App\Support\Money;

class DirectorQueueWidget extends RoleQueueWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Директор по продажам';

    protected static function roles(): array
    {
        return [Role::SalesDirector];
    }

    protected function headingIcon(): string
    {
        return 'heroicon-o-presentation-chart-line';
    }

    protected function getStats(): array
    {
        $q = $this->q();
        $sold = $q->soldThisMonth();
        $revenue = Money::toMinor((clone $sold)->sum('total_amount'));
        $cost = Money::toMinor((clone $sold)->sum('total_cost'));
        $marketing = $q->complimentaryThisMonth();

        $freeIssues = $q->freeIssuesAwaitingApproval()->count();

        return [
            $this->counter('Бесплатные ждут согласования', $q->complimentaryAwaitingApproval(),
                CertificateResource::getUrl('index', ['filters' => ['awaiting_approval' => ['isActive' => true]]]), 'danger',
                $freeIssues > 0 ? __('нужно ваше решение · в группах: :p1', ['p1' => $freeIssues]) : __('нужно ваше решение'), 'heroicon-o-hand-raised'),
            $this->metric('Продажи за месяц', Money::format(Money::fromMinor($revenue)),
                __(':p1 серт. · маржа :p2', ['p1' => (clone $sold)->count(), 'p2' => Money::format(Money::fromMinor($revenue - $cost))]), 'heroicon-o-chart-bar'),
            $this->metric('Маркетинговый расход за месяц', Money::format((clone $marketing)->sum('marketing_cost')),
                __(':p1 бесплатных · по себестоимости', ['p1' => (clone $marketing)->count()]), 'heroicon-o-megaphone'),
            $this->counter('Брони по исключению', ReservationException::query()->where('status', 'pending'), ReservationExceptions::getUrl(), 'warning',
                'истёкшие сертификаты — нужно ваше решение', 'heroicon-o-calendar-days'),
            $this->metric('Комиссии на согласовании', Money::format(Money::fromMinor(collect(app(CommissionManager::class)->summary())->sum('to_approve_minor'))),
                'начислены за использованные сертификаты', 'heroicon-o-receipt-percent')->url(Commissions::getUrl()),
            $this->counter('Ручные изменения продаж', Certificate::query()->whereIn('id', AuditLog::query()->whereIn('event', ['sale_date_overridden', 'seller_overridden'])
                ->where('auditable_type', (new Certificate)->getMorphClass())->where('created_at', '>=', now()->subDays(7))->select('auditable_id')),
                CertificateResource::getUrl('index', ['filters' => ['manual_override' => ['isActive' => true]]]), 'warning',
                'дата продажи или продавец за 7 дней — с причиной', 'heroicon-o-pencil-square'),
            $this->counter('Цены ждут подтверждения', ServicePrice::query()->whereNull('approved_at'), ServiceResource::getUrl('index'), 'warning', 'подтверждает администратор', 'heroicon-o-clock'),
            $this->counter('Услуги без цены на сегодня', $q->servicesWithoutPrice(), ServiceResource::getUrl('index'), 'danger', 'не продаются', 'heroicon-o-tag'),
            $this->counter('Услуги без себестоимости', $q->servicesWithoutCost(), ServiceResource::getUrl('index'), 'warning', 'маржа неполная', 'heroicon-o-calculator'),
        ];
    }
}
