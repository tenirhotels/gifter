<?php

namespace App\Filament\Pages;

use App\Enums\CommissionStatus;
use App\Enums\Permission;
use App\Filament\Support\CommissionPeriod;
use App\Filament\Support\TranslatesPageLabels;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificateCommission;
use App\Models\User;
use App\Services\CommissionManager;
use App\Services\SellerReport;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
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

    /** @var array<int, array<string, int>>|null */
    private ?array $rows = null;

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

    /** @return array<string, int> */
    private function row(int $sellerId): array
    {
        [$from, $to] = CommissionPeriod::range($this->getTableFilterState('period'));
        $this->rows ??= app(CommissionManager::class)->summary(null, $from, $to);

        return $this->rows[$sellerId] ?? ['sold' => 0, 'sales_minor' => 0, 'redeemed' => 0, 'not_redeemed' => 0, 'no_reservation' => 0, 'expired' => 0,
            'earned_minor' => 0, 'pending_minor' => 0, 'to_approve_minor' => 0, 'approved_minor' => 0, 'paid_minor' => 0];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportReport')->label('Отчёт Excel')->icon('heroicon-o-arrow-down-tray')
                ->modalHeading(__('Отчёт по продавцам в Excel'))
                ->modalDescription(__('Сводка, список сертификатов и разбивка по месяцам за выбранный период.'))
                ->modalSubmitActionLabel(__('Скачать'))
                ->fillForm(fn () => ['basis' => SellerReport::USED, 'preset' => 'this', 'seller' => null])
                ->schema([
                    Radio::make('basis')->label('Срез')->options([
                        SellerReport::USED => __('По использованию (месяц начисления комиссии)'),
                        SellerReport::SOLD => __('По дате продажи'),
                    ])->required(),
                    Select::make('preset')->label('Период')->options([
                        'this' => __('Этот месяц'), 'last' => __('Прошлый месяц'), 'quarter' => __('Этот квартал'), 'year' => __('Этот год'),
                        'all' => __('Весь период'), 'custom' => __('Свой период'),
                    ])->required()->live(),
                    DatePicker::make('from')->label('С')->native(false)->displayFormat('d.m.Y')->visible(fn (Get $get) => $get('preset') === 'custom'),
                    DatePicker::make('until')->label('По')->native(false)->displayFormat('d.m.Y')->visible(fn (Get $get) => $get('preset') === 'custom'),
                    Select::make('seller')->label('Продавец')->options(fn () => User::query()->whereIn('id', Certificate::query()->whereNotNull('issued_at')->whereNotNull('sold_by')->select('sold_by'))->orderBy('name')->pluck('name', 'id'))
                        ->placeholder(__('Все продавцы')),
                ])
                ->action(function (array $data): BinaryFileResponse {
                    [$from, $to] = SellerReport::range($data['preset'] ?? null, $data['from'] ?? null, $data['until'] ?? null);
                    $report = new SellerReport($data['basis'] ?? SellerReport::USED, $from, $to, filled($data['seller'] ?? null) ? (int) $data['seller'] : null);
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
            ->query(fn () => User::query()->whereIn('id', Certificate::query()->whereNotNull('issued_at')->whereNotNull('sold_by')->select('sold_by')))
            ->columns([
                TextColumn::make('name')->label('Продавец')->searchable()->weight('bold'),
                TextColumn::make('sold')->label('Продано')->state(fn (User $record) => $this->row($record->id)['sold'])
                    ->description(fn (User $record) => $money($this->row($record->id)['sales_minor'])),
                TextColumn::make('redeemed')->label('Использовано')->state(fn (User $record) => $this->row($record->id)['redeemed']),
                TextColumn::make('not_redeemed')->label('Не использовано')->state(fn (User $record) => $this->row($record->id)['not_redeemed'])
                    ->description(fn (User $record) => ($r = $this->row($record->id))['expired'] ? __('истекло: :p1', ['p1' => $r['expired']]) : null),
                TextColumn::make('no_reservation')->label('Без брони')->state(fn (User $record) => $this->row($record->id)['no_reservation'])
                    ->color(fn (User $record) => $this->row($record->id)['no_reservation'] > 0 ? 'warning' : null),
                TextColumn::make('earned')->label('Начислено')->state(fn (User $record) => $money($this->row($record->id)['earned_minor']))->weight('bold'),
                TextColumn::make('pending')->label('К выплате')
                    ->state(fn (User $record) => $money($this->row($record->id)['pending_minor']))
                    ->description(fn (User $record) => ($r = $this->row($record->id))['pending_minor']
                        ? __('согласовать: :p1 · выплатить: :p2', ['p1' => $money($r['to_approve_minor']), 'p2' => $money($r['approved_minor'])]) : null)
                    ->color(fn (User $record) => $this->row($record->id)['pending_minor'] > 0 ? 'warning' : null),
                TextColumn::make('paid')->label('Выплачено')->state(fn (User $record) => $money($this->row($record->id)['paid_minor']))->color('success'),
            ])
            ->filters([
                // Период действует на суммы комиссий (по месяцу погашения); строки по продажам остаются полными.
                CommissionPeriod::filter()->query(fn ($query) => $query),
            ])
            ->recordActions([
                Action::make('certificates')->label('Сертификаты')->icon('heroicon-o-list-bullet')
                    ->url(fn (User $record) => SellerCommissions::getUrl(['seller' => $record->id])),
            ])
            ->recordUrl(fn (User $record) => SellerCommissions::getUrl(['seller' => $record->id]))
            ->defaultSort('name')
            ->emptyStateHeading(__('Продаж с комиссией пока нет'));
    }
}
