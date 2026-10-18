<?php

namespace App\Filament\Widgets;

use App\Enums\BatchStatus;
use App\Enums\Role;
use App\Filament\Resources\CertificateBatches\CertificateBatchResource;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\RoleQueueWidget;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DirectorQueueWidget extends RoleQueueWidget
{
    protected static ?int $sort = 2;

    protected int|array|null $columns = ['default' => 2, 'md' => 3, 'xl' => 6];

    protected ?string $heading = 'Директор по продажам';

    protected static function roles(): array
    {
        return [Role::SalesDirector];
    }

    protected function getStats(): array
    {
        $q = $this->q();
        $sold = $q->soldThisMonth();
        $revenue = Money::toMinor((clone $sold)->sum('total_amount'));
        $cost = Money::toMinor((clone $sold)->sum('total_cost'));
        $marketing = $q->complimentaryThisMonth();

        return [
            $this->counter('Бесплатные ждут согласования', $q->complimentaryAwaitingApproval(),
                CertificateResource::getUrl('index', ['filters' => ['awaiting_approval' => ['isActive' => true]]]), 'danger', 'нужно ваше решение'),
            $this->counter('Блоки ждут согласования', $q->batches(BatchStatus::Draft),
                CertificateBatchResource::getUrl('index', ['filters' => ['status' => ['value' => 'draft']]]), 'danger', 'бесплатные блоками'),
            Stat::make('Продажи за месяц', Money::format(Money::fromMinor($revenue)))
                ->description((clone $sold)->count().' серт. · маржа '.Money::format(Money::fromMinor($revenue - $cost)))
                ->color('gray'),
            Stat::make('Маркетинговый расход за месяц', Money::format((clone $marketing)->sum('total_cost')))
                ->description((clone $marketing)->count().' бесплатных · по себестоимости')
                ->color('gray'),
            $this->counter('Услуги без цены на сегодня', $q->servicesWithoutPrice(), ServiceResource::getUrl('index'), 'danger', 'не продаются'),
            $this->counter('Услуги без себестоимости', $q->servicesWithoutCost(), ServiceResource::getUrl('index'), 'warning', 'маржа неполная'),
        ];
    }
}
