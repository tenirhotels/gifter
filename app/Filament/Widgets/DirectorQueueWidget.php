<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\RoleQueueWidget;
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
            $this->counter('Бесплатные сертификаты ждут согласования', $q->complimentaryAwaitingApproval(),
                CertificateResource::getUrl('index', ['filters' => ['awaiting_approval' => ['isActive' => true]]]), 'danger',
                $freeIssues > 0 ? "нужно ваше решение · в группах: {$freeIssues}" : 'нужно ваше решение', 'heroicon-o-hand-raised'),
            $this->metric('Продажи за месяц', Money::format(Money::fromMinor($revenue)),
                (clone $sold)->count().' серт. · маржа '.Money::format(Money::fromMinor($revenue - $cost)), 'heroicon-o-chart-bar'),
            $this->metric('Маркетинговый расход за месяц', Money::format((clone $marketing)->sum('marketing_cost')),
                (clone $marketing)->count().' бесплатных · по себестоимости', 'heroicon-o-megaphone'),
            $this->counter('Услуги без цены на сегодня', $q->servicesWithoutPrice(), ServiceResource::getUrl('index'), 'danger', 'не продаются', 'heroicon-o-tag'),
            $this->counter('Услуги без себестоимости', $q->servicesWithoutCost(), ServiceResource::getUrl('index'), 'warning', 'маржа неполная', 'heroicon-o-calculator'),
        ];
    }
}
