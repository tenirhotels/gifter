<?php

namespace App\Filament\Widgets;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\Role;
use App\Filament\Pages\MyCommission;
use App\Filament\Support\RoleQueueWidget;
use App\Models\Certificate;
use App\Services\CommissionManager;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Мои продажи»: свои продажи и комиссия. Комиссия начисляется при погашении сертификата,
 * поэтому в центре внимания — непогашенные и сертификаты без брони. Видно только свои данные.
 */
class MyCommissionWidget extends RoleQueueWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Мои продажи';

    protected static function roles(): array
    {
        return [Role::Sales, Role::SalesManager];
    }

    protected function headingIcon(): string
    {
        return 'heroicon-o-trophy';
    }

    protected function getStats(): array
    {
        $row = app(CommissionManager::class)->summary(auth()->id())[auth()->id()] ?? null;
        $row ??= ['sold' => 0, 'sales_minor' => 0, 'redeemed' => 0, 'not_redeemed' => 0, 'no_reservation' => 0, 'expired' => 0,
            'earned_minor' => 0, 'pending_minor' => 0, 'paid_minor' => 0];
        $money = fn (int $minor) => Money::format(Money::fromMinor($minor));
        $url = MyCommission::getUrl();
        $mine = fn (): Builder => Certificate::query()->where('sold_by', auth()->id())->where('kind', CertificateKind::Sale)
            ->whereNotNull('issued_at');

        return [
            $this->metric('Продано сертификатов', $row['sold'], 'на '.$money($row['sales_minor']), 'heroicon-o-ticket'),
            $this->metric('Погашено', $row['redeemed'], 'комиссия начислена', 'heroicon-o-check-badge'),
            $this->metric('Не погашено', $row['not_redeemed'], 'комиссии пока нет', 'heroicon-o-clock'),
            $this->counter('Без брони', $mine()->where('status', CertificateStatus::Active)->whereNull('pms_booking_number'), $url, 'warning',
                'комиссия начислится после брони и погашения', 'heroicon-o-calendar'),
            $this->counter('Истекли без использования', $mine()->where('status', CertificateStatus::Expired), $url, 'gray', 'бронь по исключению — Front Office и директор', 'heroicon-o-exclamation-triangle'),
            $this->metric('Начислено', $money($row['earned_minor']), 'всего за погашенные', 'heroicon-o-banknotes'),
            $this->metric('Ожидает выплаты', $money($row['pending_minor']), 'начислено, но не выплачено', 'heroicon-o-receipt-percent'),
            $this->metric('Выплачено', $money($row['paid_minor']), 'уже получено', 'heroicon-o-check-circle'),
        ];
    }
}
