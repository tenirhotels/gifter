<?php

namespace App\Filament\Widgets;

use App\Enums\CertificateStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\Role;
use App\Filament\Pages\UpcomingArrivals;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\Fulfillments\ServiceFulfillmentResource;
use App\Filament\Support\RoleQueueWidget;

class FrontOfficeQueueWidget extends RoleQueueWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Front Office / Бронирование';

    protected static function roles(): array
    {
        return [Role::FrontOffice];
    }

    protected function headingIcon(): string
    {
        return 'heroicon-o-building-office-2';
    }

    protected function getStats(): array
    {
        $q = $this->q();
        $list = fn (array $filters) => CertificateResource::getUrl('index', ['filters' => $filters]);
        $queue = fn (array $filters) => ServiceFulfillmentResource::getUrl('index', ['filters' => $filters]);

        $arrivals = fn (array $filters) => UpcomingArrivals::getUrl(['filters' => $filters]);

        return [
            $this->counter('Заезды сегодня', $q->arrivalsToday(), $arrivals(['period' => ['value' => 'today']]), 'info', 'по броням', 'heroicon-o-arrow-right-end-on-rectangle'),
            $this->counter('Заезды: нужно внимание', $q->arrivalsNeedingAttention(), $arrivals(['period' => ['value' => '7']]), 'danger', 'ближайшие 7 дней', 'heroicon-o-exclamation-circle'),
            $this->counter('Заявки ждут отправки', $q->fulfillments()->where('status', FulfillmentStatus::Pending),
                $queue(['status' => ['values' => ['pending']]]), 'warning', 'отправить поставщику', 'heroicon-o-paper-airplane'),
            $this->counter('Ждут подтверждения', $q->fulfillmentsAwaitingConfirmation(),
                $queue(['status' => ['values' => ['requested', 'scheduled']]]), 'warning', 'напомнить поставщику', 'heroicon-o-phone'),
            $this->counter('Услуги сегодня', $q->fulfillmentsToday(), $queue(['when' => ['value' => 'today']]), 'info', 'ужины, трансферы, билеты', 'heroicon-o-clock'),
            $this->counter('Сейчас в отеле', $q->status(CertificateStatus::InUse), $list(['status' => ['values' => ['in_use']]]), 'info', 'погасить при выезде', 'heroicon-o-home-modern'),
            $this->counter('Забронированы', $q->status(CertificateStatus::Reserved), $list(['status' => ['values' => ['reserved']]]), 'info', 'ждут заезда', 'heroicon-o-calendar-days'),
            $this->counter('Истекают ≤ 14 дней', $q->expiringSoon(), $list(['expiring' => ['isActive' => true]]), 'danger', 'активные и забронированные', 'heroicon-o-exclamation-triangle'),
            $this->counter('Активны, без брони', $q->status(CertificateStatus::Active), $list(['status' => ['values' => ['active']]]), 'gray', 'могут позвонить', 'heroicon-o-ticket'),
        ];
    }
}
