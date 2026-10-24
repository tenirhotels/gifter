<?php

namespace App\Filament\Pages;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\CommissionStatus;
use App\Enums\Permission;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Support\CommissionPeriod;
use App\Filament\Support\TranslatesPageLabels;
use App\Filament\Widgets\MySalesWidget;
use App\Models\Certificate;
use App\Support\CertificateNumber;
use App\Support\Money;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** «Мои продажи»: мои проданные сертификаты и комиссия по каждому. Только свои данные. */
class MyCommission extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    // Без группы: пункт стоит сразу под «Инфопанелью».
    protected static ?int $navigationSort = -1;

    protected static ?string $navigationLabel = 'Мои продажи';

    protected static ?string $title = 'Мои продажи';

    protected static ?string $slug = 'my-commission';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permission::ViewOwnCommissions);
    }

    protected function getHeaderWidgets(): array
    {
        return [MySalesWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Certificate::query()->where('sold_by', auth()->id())->where('kind', CertificateKind::Sale)->whereNotNull('issued_at')
                ->with(['commission', 'recipient', 'package']))
            ->columns([
                TextColumn::make('number')->label('Сертификат')->searchable(query: CertificateNumber::searchQuery())
                    ->url(fn (Certificate $record) => CertificateResource::getUrl('view', ['record' => $record]))
                    ->color('primary')->description(fn (Certificate $record) => $record->package?->name),
                TextColumn::make('sold_at')->label('Дата продажи')->date('d.m.Y')->sortable(),
                TextColumn::make('total_amount')->label('Стоимость')->formatStateUsing(fn ($state) => Money::format($state))->sortable(),
                TextColumn::make('status')->label('Статус')->badge(),
                TextColumn::make('reservation')->label('Бронирование')
                    ->state(fn (Certificate $record) => match (true) {
                        ! $record->hasReservation() => __('Бронь не создана'),
                        $record->pms_check_status === 'cancelled' => __('Бронь отменена'),
                        default => '№ '.$record->pms_booking_number,
                    })
                    ->description(fn (Certificate $record) => $record->hasReservation() && $record->pms_check_status !== 'cancelled' && $record->arrival_date && $record->departure_date
                        ? $record->arrival_date->format('d.m').'–'.$record->departure_date->format('d.m.Y') : null)
                    ->color(fn (Certificate $record) => ! $record->hasReservation() && in_array($record->status, [CertificateStatus::Active, CertificateStatus::Expired], true) ? 'warning' : null),
                TextColumn::make('commission_amount')->label('Комиссионные')
                    ->state(fn (Certificate $record) => $record->commission ? Money::format($record->commission->amount) : '—')
                    ->weight('bold')
                    ->description(fn (Certificate $record) => self::commissionNote($record)),
                TextColumn::make('commission.status')->label('Выплата')->placeholder('—')
                    ->state(fn (Certificate $record) => $record->commission ? match ($record->commission->status) {
                        CommissionStatus::Earned => __('Начислена'),
                        CommissionStatus::Approved => __('К выплате'),
                        CommissionStatus::Paid => __('Выплачена'),
                        default => __('Не начислена'),
                    } : null)
                    ->badge()->color(fn (?string $state) => match ($state) {
                        __('Выплачена') => 'success', __('К выплате') => 'warning', __('Начислена') => 'info', default => 'gray',
                    }),
            ])
            ->filters([
                CommissionPeriod::filter('earned_at', 'commission'),
                SelectFilter::make('status')->label('Статус сертификата')->options(CertificateStatus::class)->multiple(),
                Filter::make('no_reservation')->label('Требуют внимания (без брони и истёкшие)')
                    ->query(fn (Builder $query) => $query->where(fn (Builder $q) => $q->where(fn (Builder $a) => $a->where('status', CertificateStatus::Active)->whereNull('pms_booking_number'))->orWhere('status', CertificateStatus::Expired))),
            ])
            ->defaultSort('sold_at', 'desc')
            ->emptyStateHeading(__('Выпущенных продаж пока нет'))
            ->emptyStateDescription(__('Комиссия начисляется, когда сертификат использован.'));
    }

    /** Состояние комиссии простыми словами: ожидается, начислено, к выплате, выплачено. */
    private static function commissionNote(Certificate $certificate): string
    {
        $commission = $certificate->commission;
        if (! $commission) {
            return __('не предусмотрена');
        }

        return match (true) {
            $commission->status === CommissionStatus::PendingRedemption => __('ожидается'),
            $commission->status === CommissionStatus::Earned => __('начислено'),
            $commission->status === CommissionStatus::Approved => __('к выплате'),
            $commission->status === CommissionStatus::Paid => __('выплачено'),
            $certificate->status === CertificateStatus::Expired => __('не начислены (истёк без использования)'),
            default => __('не начислены'),
        };
    }
}
