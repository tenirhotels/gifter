<?php

namespace App\Filament\Widgets;

use App\Enums\CertificateStatus;
use App\Enums\Role;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Support\RoleQueueWidget;

class FrontOfficeQueueWidget extends RoleQueueWidget
{
    protected static ?int $sort = 3;

    protected int|array|null $columns = ['default' => 2, 'md' => 3, 'xl' => 6];

    protected ?string $heading = 'Front Office / Бронирование';

    protected static function roles(): array
    {
        return [Role::FrontOffice];
    }

    protected function getStats(): array
    {
        $q = $this->q();
        $list = fn (array $filters) => CertificateResource::getUrl('index', ['filters' => $filters]);

        return [
            $this->counter('Сейчас в отеле', $q->status(CertificateStatus::InUse), $list(['status' => ['values' => ['in_use']]]), 'info', 'погасить при выезде'),
            $this->counter('Забронированы', $q->status(CertificateStatus::Reserved), $list(['status' => ['values' => ['reserved']]]), 'info'),
            $this->counter('Истекают ≤ 14 дней', $q->expiringSoon(), $list(['expiring' => ['isActive' => true]]), 'danger', 'активные и забронированные'),
            $this->counter('Активны, без брони', $q->status(CertificateStatus::Active), $list(['status' => ['values' => ['active']]]), 'gray'),
        ];
    }
}
