<?php

namespace App\Filament\Pages;

use App\Enums\ArrivalReadinessLevel;
use App\Enums\CertificateStatus;
use App\Enums\Permission;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Support\TranslatesPageLabels;
use App\Models\Certificate;
use App\Models\ServiceFulfillment;
use App\Policies\CertificatePolicy;
use App\Support\ArrivalReadiness;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * «Ближайшие заезды» (фаза 6): забронированные гости по сертификатам и готовность
 * к их приезду — «Готов / Требует действия / Исключение» (ArrivalReadiness).
 */
class UpcomingArrivals extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected string $view = 'filament.pages.upcoming-arrivals';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Ближайшие заезды';

    protected static ?string $title = 'Ближайшие заезды';

    protected static ?string $slug = 'arrivals';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can(Permission::ManageReservations) || $user?->can(Permission::ManageFulfillments));
    }

    public static function getNavigationBadge(): ?string
    {
        $count = CertificatePolicy::scopeVisible(Certificate::query(), auth()->user())
            ->where('status', CertificateStatus::Reserved)->whereDate('arrival_date', today()->toDateString())->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Заезды сегодня');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => CertificatePolicy::scopeVisible(Certificate::query(), auth()->user())
                ->whereIn('status', [CertificateStatus::Reserved, CertificateStatus::InUse])
                ->whereNotNull('arrival_date')
                ->with(['recipient', 'package', 'fulfillments.item']))
            ->columns([
                TextColumn::make('arrival_date')->label('Заезд')->date('d.m.Y')->sortable()
                    ->description(fn (Certificate $record) => self::dayLabel($record->arrival_date)),
                TextColumn::make('booking_guest_name')->label('Гость')->searchable()->weight('medium')
                    ->description(fn (Certificate $record) => __(':p1 ноч., до :p2', ['p1' => $record->arrival_date->diffInDays($record->departure_date), 'p2' => $record->departure_date->format('d.m')])),
                TextColumn::make('number')->label('Сертификат')->searchable()
                    ->url(fn (Certificate $record) => CertificateResource::getUrl('view', ['record' => $record]))
                    ->description(fn (Certificate $record) => $record->package?->name),
                TextColumn::make('pms_booking_number')->label('Номер брони')->searchable()->copyable(),
                TextColumn::make('services')->label('Услуги')->html()->wrap()
                    ->state(fn (Certificate $record) => self::servicesHtml($record)),
                TextColumn::make('readiness')->label('Готовность')->badge()
                    ->state(fn (Certificate $record) => ArrivalReadiness::for($record)['level'])
                    ->description(fn (Certificate $record) => implode('; ', ArrivalReadiness::for($record)['reasons']) ?: null)
                    ->wrap(),
                TextColumn::make('status')->label('Статус')->badge()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('period')->label('Период')
                    ->options(['today' => __('Сегодня'), 'tomorrow' => __('Завтра'), '3' => __('Ближайшие 3 дня'), '7' => __('Ближайшие 7 дней'), 'in_house' => __('Сейчас в отеле')])
                    ->default('7')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'today' => $query->where('status', CertificateStatus::Reserved)->whereDate('arrival_date', today()->toDateString()),
                        'tomorrow' => $query->where('status', CertificateStatus::Reserved)->whereDate('arrival_date', today()->addDay()->toDateString()),
                        '3', '7' => $query->where('status', CertificateStatus::Reserved)
                            ->whereBetween('arrival_date', [today()->toDateString(), today()->addDays((int) $data['value'] - 1)->toDateString()]),
                        'in_house' => $query->where('status', CertificateStatus::InUse),
                        default => $query,
                    }),
                Filter::make('range')->label('Свой период')
                    ->schema([DatePicker::make('from')->label('Заезд с'), DatePicker::make('until')->label('Заезд по')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('arrival_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('arrival_date', '<=', $date)))
                    ->indicateUsing(fn (array $data) => ($data['from'] ?? null) || ($data['until'] ?? null)
                        ? __('Заезд: :p1', ['p1' => collect([$data['from'] ? Carbon::parse($data['from'])->format('d.m.Y') : null, $data['until'] ? Carbon::parse($data['until'])->format('d.m.Y') : null])->filter()->implode(' — ')])
                        : null),
                SelectFilter::make('readiness')->label('Готовность')->options(ArrivalReadinessLevel::class)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? ArrivalReadiness::scope($query, ArrivalReadinessLevel::from($data['value']))
                        : $query),
            ])
            ->recordUrl(fn (Certificate $record) => CertificateResource::getUrl('view', ['record' => $record]))
            ->defaultSort('arrival_date')
            ->emptyStateHeading(__('Заездов по сертификатам нет'))
            ->emptyStateDescription(__('Измените период или снимите фильтры.'));
    }

    private static function dayLabel(Carbon $date): string
    {
        return match (true) {
            $date->isToday() => __('сегодня'),
            $date->isTomorrow() => __('завтра'),
            $date->isPast() => __('был :p1', ['p1' => $date->translatedFormat('l')]),
            default => $date->translatedFormat('l'),
        };
    }

    /** Услуги, требующие подготовки: иконка по статусу заявки и время. */
    private static function servicesHtml(Certificate $record): HtmlString
    {
        $open = $record->fulfillments->filter(fn (ServiceFulfillment $f) => $f->status->isOpen() || $f->status->value === 'completed');
        if ($open->isEmpty()) {
            return new HtmlString('<span class="gf-muted">'.e(__('подготовка не нужна')).'</span>');
        }

        return new HtmlString($open->map(function (ServiceFulfillment $f) {
            $ok = ! ArrivalReadiness::needsAction($f);

            return '<div class="gf-arrival-service" data-ok="'.($ok ? '1' : '0').'"><span class="gf-dot" aria-hidden="true"></span>'
                .e($f->serviceLabel()).' <span class="gf-muted">— '.e($f->status->getLabel()).($f->whenLabel() ? ', '.e($f->whenLabel()) : '').'</span></div>';
        })->implode(''));
    }
}
