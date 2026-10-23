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
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * «Комиссии» — единый отчёт по продавцам для Финансов и директора: продажи и комиссии со срезами (по использованию или
 * по дате продажи), периодами, разбивкой по месяцам и выгрузкой в Excel. «Сертификаты» раскрывают строку до отдельных
 * сертификатов, «Согласование и выплаты» ведёт к работе с комиссиями продавца. Выгрузка пишется в журнал аудита.
 */
class Commissions extends Page
{
    use TranslatesPageLabels;

    protected string $view = 'filament.pages.commissions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Комиссии';

    protected static ?string $title = 'Комиссии продавцов';

    protected static ?string $slug = 'commissions';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationBadge(): ?string
    {
        // Только наличие работы: один быстрый запрос, а не полная сводка по всем сертификатам на каждой странице.
        $status = auth()->user()?->can(Permission::PayCommissions) ? CommissionStatus::Approved : CommissionStatus::Earned;

        return CertificateCommission::query()->where('status', $status)->exists() ? '●' : null;
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permission::ViewAllCommissions);
    }

    public function mount(): void
    {
        $this->form->fill(['basis' => SellerReport::SOLD, 'preset' => 'all', 'from' => null, 'until' => null, 'seller' => null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Grid::make(['default' => 1, 'lg' => 4])->schema([
                Radio::make('basis')->label('Срез')->options([
                    SellerReport::SOLD => __('По дате продажи'),
                    SellerReport::USED => __('По использованию (месяц начисления комиссии)'),
                ])->default(SellerReport::SOLD)->live()->required(),
                Select::make('preset')->label('Период')->options([
                    'all' => __('Весь период'), 'this' => __('Этот месяц'), 'last' => __('Прошлый месяц'), 'quarter' => __('Этот квартал'), 'year' => __('Этот год'),
                    'custom' => __('Свой период'),
                ])->default('all')->live()->required(),
                DatePicker::make('from')->label('С')->native(false)->displayFormat('d.m.Y')->live()
                    ->visible(fn (Get $get) => $get('preset') === 'custom'),
                DatePicker::make('until')->label('По')->native(false)->displayFormat('d.m.Y')->live()
                    ->visible(fn (Get $get) => $get('preset') === 'custom'),
                Select::make('seller')->label('Продавец')->options(fn () => User::query()->whereIn('id', Certificate::query()->whereNotNull('issued_at')->whereNotNull('sold_by')->select('sold_by'))->orderBy('name')->pluck('name', 'id'))
                    ->placeholder(__('Все продавцы'))->live(),
            ]),
        ]);
    }

    public function report(): SellerReport
    {
        [$from, $to] = SellerReport::range($this->data['preset'] ?? 'all', $this->data['from'] ?? null, $this->data['until'] ?? null);

        return new SellerReport(
            ($this->data['basis'] ?? null) === SellerReport::USED ? SellerReport::USED : SellerReport::SOLD,
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
                'count' => $summary->sum('count'), 'sales' => $money($summary->sum('sales_minor')), 'used' => $summary->sum('used'), 'not_used' => $summary->sum('not_used'), 'no_reservation' => $summary->sum('no_reservation'),
                'earned' => $money($summary->sum('earned_minor')), 'to_approve' => $money($summary->sum('to_approve_minor')),
                'approved' => $money($summary->sum('approved_minor')), 'paid' => $money($summary->sum('paid_minor')),
            ],
            'months' => $report->months(),
            'money' => $money,
        ];
    }
}
