<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Support\TranslatesPageLabels;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\User;
use App\Services\SellerReport;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * «Отчёт по продавцам» для Финансов и директора: сводка и разбивка по месяцам за период, детализация — в выгрузке Excel.
 * Срез — по месяцу использования (когда начислена комиссия) или по дате продажи. Выгрузка пишется в журнал аудита.
 */
class SellerReports extends Page
{
    use TranslatesPageLabels;

    protected string $view = 'filament.pages.seller-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Деньги';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Отчёт по продавцам';

    protected static ?string $title = 'Отчёт по продавцам';

    protected static ?string $slug = 'seller-report';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permission::ViewAllCommissions);
    }

    public function mount(): void
    {
        $this->form->fill(['basis' => SellerReport::USED, 'preset' => 'this', 'from' => null, 'until' => null, 'seller' => null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Grid::make(['default' => 1, 'lg' => 4])->schema([
                Radio::make('basis')->label('Срез')->options([
                    SellerReport::USED => __('По использованию (месяц начисления комиссии)'),
                    SellerReport::SOLD => __('По дате продажи'),
                ])->default(SellerReport::USED)->live()->required(),
                Select::make('preset')->label('Период')->options([
                    'this' => __('Этот месяц'), 'last' => __('Прошлый месяц'), 'quarter' => __('Этот квартал'), 'year' => __('Этот год'),
                    'all' => __('Весь период'), 'custom' => __('Свой период'),
                ])->default('this')->live()->required(),
                DatePicker::make('from')->label('С')->native(false)->displayFormat('d.m.Y')->live()
                    ->visible(fn (Get $get) => $get('preset') === 'custom'),
                DatePicker::make('until')->label('По')->native(false)->displayFormat('d.m.Y')->live()
                    ->visible(fn (Get $get) => $get('preset') === 'custom'),
                Select::make('seller')->label('Продавец')->options(fn () => User::query()->whereIn('id', Certificate::query()->whereNotNull('issued_at')->whereNotNull('sold_by')->select('sold_by'))->orderBy('name')->pluck('name', 'id'))
                    ->placeholder(__('Все продавцы'))->live(),
            ]),
        ]);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function range(): array
    {
        $today = now();

        return match ($this->data['preset'] ?? 'this') {
            'this' => [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString()],
            'last' => [$today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'quarter' => [$today->copy()->startOfQuarter()->toDateString(), $today->copy()->endOfQuarter()->toDateString()],
            'year' => [$today->copy()->startOfYear()->toDateString(), $today->copy()->endOfYear()->toDateString()],
            'custom' => [$this->data['from'] ?? null, $this->data['until'] ?? null],
            default => [null, null],
        };
    }

    public function report(): SellerReport
    {
        [$from, $to] = $this->range();

        return new SellerReport(
            in_array($this->data['basis'] ?? null, [SellerReport::USED, SellerReport::SOLD], true) ? $this->data['basis'] : SellerReport::USED,
            $from, $to, filled($this->data['seller'] ?? null) ? (int) $this->data['seller'] : null,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Скачать Excel')->icon('heroicon-o-arrow-down-tray')
                ->action(function (): BinaryFileResponse {
                    $report = $this->report();
                    AuditLog::record('seller_report_exported', auth()->user(), null, [
                        'basis' => $report->basis, 'from' => $report->from, 'to' => $report->to, 'seller_id' => $report->sellerId,
                    ]);

                    return response()->download($report->toXlsx(), 'sellers-'.now()->format('Ymd-His').'.xlsx')->deleteFileAfterSend();
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $report = $this->report();
        $summary = $report->summary();
        $money = fn (int $minor) => Money::format(Money::fromMinor($minor));

        return [
            'periodLabel' => $report->periodLabel(),
            'summary' => $summary,
            'totals' => [
                'count' => $summary->sum('count'), 'sales' => $money($summary->sum('sales_minor')), 'used' => $summary->sum('used'), 'not_used' => $summary->sum('not_used'),
                'earned' => $money($summary->sum('earned_minor')), 'to_approve' => $money($summary->sum('to_approve_minor')),
                'approved' => $money($summary->sum('approved_minor')), 'paid' => $money($summary->sum('paid_minor')),
            ],
            'months' => $report->months(),
            'money' => $money,
        ];
    }
}
