<?php

namespace App\Filament\Pages;

use App\Enums\CommissionStatus;
use App\Enums\Permission;
use App\Filament\Support\TranslatesPageLabels;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificateCommission;
use App\Models\User;
use App\Services\SellerReport;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * «Комиссии» — сводка по продавцам для Финансов и директора: продано, погашено, не погашено,
 * без брони, начислено, ожидает выплаты, выплачено. «Сертификаты» раскрывают сумму до отдельных сертификатов.
 * «Отчёт Excel» — детальная выгрузка по продавцам за период: сводка, сертификаты, разбивка по месяцам (SellerReport).
 */
class Commissions extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Деньги';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Комиссии';

    protected static ?string $title = 'Комиссии продавцов';

    protected static ?string $slug = 'commissions';

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $summary = null;

    private ?string $summaryKey = null;

    /** @var array{months: list<string>, rows: list<array<string, mixed>>}|null */
    private ?array $months = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permission::ViewAllCommissions);
    }

    public static function getNavigationBadge(): ?string
    {
        // Только наличие работы: один быстрый запрос, а не полная сводка по всем сертификатам на каждой странице.
        $status = auth()->user()?->can(Permission::PayCommissions) ? CommissionStatus::Approved : CommissionStatus::Earned;

        return CertificateCommission::query()->where('status', $status)->exists() ? '●' : null;
    }

    /** Отчёт по текущим фильтрам: срез (по продаже / по использованию), период, продавец. */
    private function report(): SellerReport
    {
        $state = $this->getTableFilterState('report') ?? [];
        [$from, $to] = SellerReport::range($state['preset'] ?? 'all', $state['from'] ?? null, $state['until'] ?? null);

        return new SellerReport(
            ($state['basis'] ?? null) === SellerReport::USED ? SellerReport::USED : SellerReport::SOLD,
            $from, $to, filled($state['seller'] ?? null) ? (int) $state['seller'] : null,
        );
    }

    /** @return Collection<int, array<string, mixed>> */
    private function summary(): Collection
    {
        $key = json_encode($this->getTableFilterState('report'));
        if ($this->summaryKey !== $key || $this->summary === null) {
            $this->summaryKey = $key;
            $this->summary = $this->report()->summary()->keyBy('seller_id');
            $this->months = null;
        }

        return $this->summary;
    }

    /** @return array<string, mixed> */
    private function row(int $sellerId): array
    {
        return $this->summary()[$sellerId] ?? ['count' => 0, 'sales_minor' => 0, 'used' => 0, 'not_used' => 0, 'expired' => 0, 'no_reservation' => 0,
            'earned_minor' => 0, 'to_approve_minor' => 0, 'approved_minor' => 0, 'paid_minor' => 0];
    }

    /** @return array{months: list<string>, rows: list<array<string, mixed>>} */
    private function months(): array
    {
        $this->summary();

        return $this->months ??= $this->report()->months();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportReport')->label('Скачать Excel')->icon('heroicon-o-arrow-down-tray')
                ->tooltip(__('Выгрузка по выбранным срезу, периоду и продавцу: сводка, сертификаты, по месяцам'))
                ->action(function (): BinaryFileResponse {
                    $report = $this->report();
                    AuditLog::record('seller_report_exported', auth()->user(), null, [
                        'basis' => $report->basis, 'from' => $report->from, 'to' => $report->to, 'seller_id' => $report->sellerId,
                    ]);

                    return response()->download($report->toXlsx(), 'sellers-'.now()->format('Ymd-His').'.xlsx')->deleteFileAfterSend();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $money = fn (int $minor) => Money::format(Money::fromMinor($minor));

        return $table
            ->query(fn () => User::query()->whereIn('id', $this->summary()->keys()))
            ->columns([
                TextColumn::make('name')->label('Продавец')->searchable()->weight('bold'),
                TextColumn::make('sold')->label('Продано')->state(fn (User $record) => $this->row($record->id)['count'])
                    ->description(fn (User $record) => $money($this->row($record->id)['sales_minor'])),
                TextColumn::make('redeemed')->label('Использовано')->state(fn (User $record) => $this->row($record->id)['used']),
                TextColumn::make('not_redeemed')->label('Не использовано')->state(fn (User $record) => $this->row($record->id)['not_used'])
                    ->description(fn (User $record) => ($r = $this->row($record->id))['expired'] ? __('истекло: :p1', ['p1' => $r['expired']]) : null),
                TextColumn::make('no_reservation')->label('Без брони')->state(fn (User $record) => $this->row($record->id)['no_reservation'])
                    ->color(fn (User $record) => $this->row($record->id)['no_reservation'] > 0 ? 'warning' : null),
                TextColumn::make('earned')->label('Начислено')->state(fn (User $record) => $money($this->row($record->id)['earned_minor']))->weight('bold'),
                TextColumn::make('pending')->label('К выплате')
                    ->state(fn (User $record) => $money($this->row($record->id)['to_approve_minor'] + $this->row($record->id)['approved_minor']))
                    ->description(fn (User $record) => ($r = $this->row($record->id))['to_approve_minor'] + $r['approved_minor']
                        ? __('согласовать: :p1 · выплатить: :p2', ['p1' => $money($r['to_approve_minor']), 'p2' => $money($r['approved_minor'])]) : null)
                    ->color(fn (User $record) => $this->row($record->id)['to_approve_minor'] + $this->row($record->id)['approved_minor'] > 0 ? 'warning' : null),
                TextColumn::make('paid')->label('Выплачено')->state(fn (User $record) => $money($this->row($record->id)['paid_minor']))->color('success'),
                TextColumn::make('by_month')->label('По месяцам')->html()->wrap()
                    ->visible(fn () => ! empty($this->getTableFilterState('report')['by_month'] ?? null))
                    ->state(function (User $record) use ($money): string {
                        $row = collect($this->months()['rows'])->firstWhere('seller_id', $record->id);

                        return collect($row['values'] ?? [])->map(fn (int $minor, string $month) => e(Carbon::createFromFormat('Y-m', $month)->translatedFormat('M Y').': '.$money($minor)))->implode('<br>');
                    }),
            ])
            ->filters([
                // Срез и период управляют всей таблицей: продажи и комиссии считаются по одним и тем же сертификатам.
                Filter::make('report')->label('Срез и период')
                    ->schema([
                        Radio::make('basis')->label('Срез')->options([
                            SellerReport::SOLD => __('По дате продажи'),
                            SellerReport::USED => __('По использованию (месяц начисления комиссии)'),
                        ])->default(SellerReport::SOLD),
                        Select::make('preset')->label('Период')->options([
                            'all' => __('Весь период'), 'this' => __('Этот месяц'), 'last' => __('Прошлый месяц'), 'quarter' => __('Этот квартал'),
                            'year' => __('Этот год'), 'custom' => __('Свой период'),
                        ])->default('all')->live(),
                        DatePicker::make('from')->label('С')->native(false)->displayFormat('d.m.Y')->visible(fn (Get $get) => $get('preset') === 'custom'),
                        DatePicker::make('until')->label('По')->native(false)->displayFormat('d.m.Y')->visible(fn (Get $get) => $get('preset') === 'custom'),
                        Select::make('seller')->label('Продавец')->options(fn () => User::query()->whereIn('id', Certificate::query()->whereNotNull('issued_at')->whereNotNull('sold_by')->select('sold_by'))->orderBy('name')->pluck('name', 'id'))
                            ->placeholder(__('Все продавцы')),
                        Toggle::make('by_month')->label('Разбивка по месяцам'),
                    ])
                    ->query(fn ($query) => $query)
                    ->indicateUsing(function (array $data): ?string {
                        $report = $this->report();

                        return $report->from || $report->to || $report->sellerId || $report->basis === SellerReport::USED ? $report->periodLabel() : null;
                    }),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                Action::make('certificates')->label('Сертификаты')->icon('heroicon-o-list-bullet')
                    ->url(fn (User $record) => SellerCommissions::getUrl(['seller' => $record->id])),
            ])
            ->recordUrl(fn (User $record) => SellerCommissions::getUrl(['seller' => $record->id]))
            ->defaultSort('name')
            ->emptyStateHeading(__('Продаж с комиссией пока нет'));
    }
}
