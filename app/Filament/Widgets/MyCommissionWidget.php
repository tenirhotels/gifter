<?php

namespace App\Filament\Widgets;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\CommissionStatus;
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
use Illuminate\Support\HtmlString;

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

        $sold = $mine()->where('status', '!=', CertificateStatus::Cancelled)->whereDate('sold_at', '>=', $from)->whereDate('sold_at', '<=', $to)->get(['id', 'total_amount', 'status']);
        $soldMinor = $sold->sum(fn ($c) => Money::toMinor($c->total_amount));
        $used = $sold->where('status', CertificateStatus::Used)->count();
        $row = app(CommissionManager::class)->summary($id, $from, $to)[$id] ?? ['earned_minor' => 0, 'pending_minor' => 0, 'paid_minor' => 0];

        // Ожидается: комиссия по проданным за период, но ещё не использованным сертификатам.
        $expected = CertificateCommission::query()->where('seller_id', $id)->where('status', CommissionStatus::PendingRedemption)
            ->whereIn('certificate_id', $sold->pluck('id'))->get(['amount'])->sum(fn ($c) => Money::toMinor($c->amount));
        $hasCommission = CertificateCommission::query()->whereIn('certificate_id', $sold->pluck('id'))->exists();

        $needBooking = $mine()->where('status', CertificateStatus::Active)->whereNull('pms_booking_number')->count();
        $expired = $mine()->where('status', CertificateStatus::Expired)->count();
        $attention = $needBooking + $expired;
        $url = MyCommission::getUrl(['filters' => ['no_reservation' => ['isActive' => true]]]);

        $commissionNote = match (true) {
            $sold->isEmpty() => 'за этот период продаж нет',
            ! $hasCommission => 'правило комиссии не задано — обратитесь к администратору',
            default => 'Ожидается: '.$money($expected),
        };

        return [
            $this->metric('Продано', $sold->count(), $sold->isEmpty() ? 'за этот период продаж нет' : 'на '.$money($soldMinor).' · использовано '.$used, 'heroicon-o-ticket'),
            $this->metric('Использовано', $used, $sold->isEmpty() ? 'за этот период продаж нет' : 'из '.$sold->count().' проданных сертификатов', 'heroicon-o-check-badge'),
            $this->tile(
                Stat::make('Комиссионные', $money($row['earned_minor']))
                    ->description(new HtmlString('к выплате '.e($money($row['pending_minor'])).' · выплачено '.e($money($row['paid_minor'])).'<br><strong>'.e($commissionNote).'</strong>')),
                'heroicon-o-banknotes', 'metric', false),
            $this->tile(
                Stat::make('Требуют внимания', $attention)->url($attention ? $url : null)
                    ->description($attention
                        ? collect([$needBooking ? 'без брони: '.$needBooking : null, $expired ? 'истёк без использования: '.$expired.' — даты брони согласует директор по продажам' : null])->filter()->implode(' · ')
                        : 'нет сертификатов, требующих действий'),
                'heroicon-o-calendar', $attention > 0 ? 'warning' : 'zero', $attention > 0),
        ];
    }
}
