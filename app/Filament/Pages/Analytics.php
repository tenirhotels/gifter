<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Support\TranslatesPageLabels;
use App\Filament\Widgets\Analytics\AnalyticsChannelsChart;
use App\Filament\Widgets\Analytics\AnalyticsCohortsTable;
use App\Filament\Widgets\Analytics\AnalyticsFlowChart;
use App\Filament\Widgets\Analytics\AnalyticsFreeChart;
use App\Filament\Widgets\Analytics\AnalyticsNotice;
use App\Filament\Widgets\Analytics\AnalyticsPackagesChart;
use App\Filament\Widgets\Analytics\AnalyticsSeasonChart;
use App\Filament\Widgets\Analytics\AnalyticsStats;
use App\Filament\Widgets\Analytics\AnalyticsYearsTable;
use App\Services\AnalyticsReport;
use App\Support\AnalyticsPeriod;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** «Аналитика»: продажа, оплата и использование сертификатов по месяцам и годам, бесплатные, популярные пакеты. */
class Analytics extends Dashboard
{
    use HasFiltersForm;
    use TranslatesPageLabels;

    protected static string $routePath = 'analytics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Деньги';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Аналитика';

    protected static ?string $title = 'Аналитика';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permission::ViewAnalytics);
    }

    public function mount(): void
    {
        $this->filters = ['period' => AnalyticsPeriod::ALL, 'compare' => false] + (array) $this->filters;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->schema([
                Select::make('period')->label('Период')->native(false)->selectablePlaceholder(false)->default(AnalyticsPeriod::ALL)
                    ->options(fn () => [
                        AnalyticsPeriod::THIS_YEAR => __('Этот год'), AnalyticsPeriod::LAST_YEAR => __('Прошлый год'), AnalyticsPeriod::LAST_12 => __('Последние 12 месяцев'), AnalyticsPeriod::ALL => __('Всё время'),
                    ] + collect(app(AnalyticsReport::class)->years())->reverse()->mapWithKeys(fn ($y) => [(string) $y => $y.' '.__('год')])->all()),
                Toggle::make('compare')->label('Сравнить с прошлым периодом')->default(false)->inline(false),
                Placeholder::make('definitions')->label('Как считаем')->columnSpan(['default' => 1, 'md' => 3, 'xl' => 1])
                    ->content(__('Продажа — по дате продажи, оплата — по дате оплаты, использование — по заезду по брони или отметке в системе.')),
            ])->columns(['default' => 1, 'md' => 3]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Скачать CSV')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->action(function () {
                    $period = AnalyticsPeriod::resolve($this->filters['period'] ?? AnalyticsPeriod::ALL, app(AnalyticsReport::class)->years());
                    $r = app(AnalyticsReport::class)->build($period);

                    return response()->streamDownload(function () use ($r) {
                        $out = fopen('php://output', 'w');
                        fwrite($out, "\xEF\xBB\xBF");
                        fputcsv($out, [__('Период'), __('Продано, ₸'), __('Продано, шт.'), __('Оплачено, ₸'), __('Возвраты, ₸'), __('Использовано, ₸'), __('Использовано, шт.'), __('Бесплатных, шт.'), __('Расход на бесплатные, ₸')], ';');
                        foreach ($r['labels'] as $i => $label) {
                            fputcsv($out, [$label, $r['sold'][$i], $r['sold_count'][$i], $r['paid'][$i], $r['refunded'][$i], $r['used'][$i], $r['used_count'][$i], $r['free_count'][$i], $r['free_cost'][$i]], ';');
                        }
                        fclose($out);
                    }, 'analytics-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
        ];
    }

    public function getWidgets(): array
    {
        // Нет данных за период — одно пустое состояние вместо набора пустых графиков.
        $period = AnalyticsPeriod::resolve($this->filters['period'] ?? AnalyticsPeriod::ALL, app(AnalyticsReport::class)->years());
        if (app(AnalyticsReport::class)->isEmpty(app(AnalyticsReport::class)->build($period))) {
            return [AnalyticsNotice::class];
        }

        return [
            AnalyticsNotice::class, AnalyticsStats::class, AnalyticsFlowChart::class, AnalyticsFreeChart::class, AnalyticsPackagesChart::class,
            AnalyticsChannelsChart::class, AnalyticsSeasonChart::class, AnalyticsYearsTable::class, AnalyticsCohortsTable::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
