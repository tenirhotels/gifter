<?php

namespace App\Filament\Widgets;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\Role;
use App\Filament\Pages\MyCommission;
use App\Filament\Support\RoleQueueWidget;
use App\Models\Certificate;
use App\Models\CertificateCommission;
use App\Services\CommissionManager;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Components\Component;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Мои продажи»: свои продажи и комиссия. Комиссия начисляется при погашении сертификата,
 * поэтому в центре внимания — непогашенные и сертификаты без брони. Видно только свои данные.
 */
/** На главной — продавцам и менеджерам; на странице «Мои продажи» (`MySalesWidget`) — всем, у кого есть это право. */
class MyCommissionWidget extends RoleQueueWidget implements HasActions
{
    use InteractsWithActions;

    /** Период показателей: month — текущий месяц, year — текущий год. */
    public string $period = 'month';

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

    public function getSectionContentComponent(): Component
    {
        $button = fn (string $key, string $label) => Action::make($key)->label($label)->size('sm')
            ->color($this->period === $key ? 'primary' : 'gray')->outlined($this->period !== $key)
            ->action(fn () => $this->period = $key);

        return parent::getSectionContentComponent()->headerActions([$button('month', 'Месяц'), $button('year', 'Год')]);
    }

    protected function getHeading(): ?string
    {
        return 'Мои продажи — '.($this->period === 'year' ? now()->format('Y').' год' : now()->translatedFormat('F Y'));
    }

    /** Продано за период по дате продажи; комиссия — по месяцу погашения (`earned_at`). */
    protected function getStats(): array
    {
        $from = ($this->period === 'year' ? now()->startOfYear() : now()->startOfMonth())->toDateString();
        $to = ($this->period === 'year' ? now()->endOfYear() : now()->endOfMonth())->toDateString();
        $id = auth()->id();
        $money = fn (int $minor) => Money::format(Money::fromMinor($minor));
        $mine = fn (): Builder => Certificate::query()->where('sold_by', $id)->where('kind', CertificateKind::Sale)->whereNotNull('issued_at');

        $sold = $mine()->where('status', '!=', CertificateStatus::Cancelled)->whereDate('sold_at', '>=', $from)->whereDate('sold_at', '<=', $to)->get(['total_amount', 'status']);
        $soldMinor = $sold->sum(fn ($c) => Money::toMinor($c->total_amount));
        $row = app(CommissionManager::class)->summary($id, $from, $to)[$id] ?? ['earned_minor' => 0, 'pending_minor' => 0, 'paid_minor' => 0];
        $redeemed = CertificateCommission::query()->where('seller_id', $id)->whereDate('earned_at', '>=', $from)->whereDate('earned_at', '<=', $to)->get(['status'])
            ->filter(fn ($c) => $c->status->isEarned())->count();

        $needBooking = $mine()->where('status', CertificateStatus::Active)->whereNull('pms_booking_number')->count();
        $expired = $mine()->where('status', CertificateStatus::Expired)->count();
        $url = MyCommission::getUrl(['filters' => ['no_reservation' => ['isActive' => true]]]);

        return [
            $this->metric('Продано', $sold->count(), 'на '.$money($soldMinor).' · не погашено '.$sold->where('status', '!=', CertificateStatus::Used)->count(), 'heroicon-o-ticket'),
            $this->metric('Погашено', $redeemed, 'комиссия начисляется в месяц погашения', 'heroicon-o-check-badge'),
            $this->metric('Комиссия', $money($row['earned_minor']), 'к выплате '.$money($row['pending_minor']).' · выплачено '.$money($row['paid_minor']), 'heroicon-o-banknotes'),
            $this->tile(
                Stat::make('Нужно посадить бронь', $needBooking + $expired)->url($url)
                    ->description('без брони: '.$needBooking.' · истекли: '.$expired.($expired ? ' — даты согласует директор по продажам' : '')),
                'heroicon-o-calendar', $needBooking + $expired > 0 ? 'warning' : 'zero', $needBooking + $expired > 0),
        ];
    }
}
