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

        return parent::getSectionContentComponent()->headerActions([$button('month', __('Месяц')), $button('year', __('Год'))]);
    }

    protected function getHeading(): ?string
    {
        return $this->period === 'year' ? __('Мои продажи — :p1 год', ['p1' => now()->format('Y')]) : __('Мои продажи — :p1', ['p1' => now()->translatedFormat('F Y')]);
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
            $sold->isEmpty() => __('за этот период продаж нет'),
            ! $hasCommission => __('правило комиссии не задано — обратитесь к администратору'),
            default => __('Ожидается: :p1', ['p1' => $money($expected)]),
        };

        return [
            $this->metric('Продано', $sold->count(), $sold->isEmpty() ? __('за этот период продаж нет') : __('на :p1 · использовано :p2', ['p1' => $money($soldMinor), 'p2' => $used]), 'heroicon-o-ticket'),
            $this->metric('Использовано', $used, $sold->isEmpty() ? __('за этот период продаж нет') : __('из :p1 проданных сертификатов', ['p1' => $sold->count()]), 'heroicon-o-check-badge'),
            $this->tile(
                Stat::make(__('Комиссионные'), $money($row['earned_minor']))
                    ->description(new HtmlString(e(__('к выплате :p1 · выплачено :p2', ['p1' => $money($row['pending_minor']), 'p2' => $money($row['paid_minor'])])).'<br><strong>'.e($commissionNote).'</strong>')),
                'heroicon-o-banknotes', 'metric', false),
            $this->tile(
                Stat::make(__('Требуют внимания'), $attention)->url($attention ? $url : null)
                    ->description($attention
                        ? collect([$needBooking ? __('без брони: :p1', ['p1' => $needBooking]) : null, $expired ? __('истёк без использования: :p1 — даты брони согласует директор по продажам', ['p1' => $expired]) : null])->filter()->implode(' · ')
                        : __('нет сертификатов, требующих действий')),
                'heroicon-o-calendar', $attention > 0 ? 'warning' : 'zero', $attention > 0),
        ];
    }
}
