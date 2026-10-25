<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Support\TranslatesPageLabels;
use App\Filament\Widgets\Analytics\AnalyticsChannelsChart;
use App\Filament\Widgets\Analytics\AnalyticsFlowChart;
use App\Filament\Widgets\Analytics\AnalyticsFreeChart;
use App\Filament\Widgets\Analytics\AnalyticsPackagesChart;
use App\Filament\Widgets\Analytics\AnalyticsStats;
use App\Filament\Widgets\Analytics\AnalyticsYearsTable;
use App\Services\AnalyticsReport;
use BackedEnum;
use Filament\Forms\Components\Select;
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

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Select::make('year')->label('Период')->placeholder('Все годы')->options(fn () => collect(app(AnalyticsReport::class)->years())->reverse()->mapWithKeys(fn ($y) => [$y => (string) $y])->all())
                    ->native(false)->helperText(__('Продажа — по дате продажи, оплата — по дате оплаты, использование — по заезду по брони или отметке в системе.')),
            ])->columns(1),
        ]);
    }

    public function getWidgets(): array
    {
        return [AnalyticsStats::class, AnalyticsFlowChart::class, AnalyticsFreeChart::class, AnalyticsPackagesChart::class, AnalyticsChannelsChart::class, AnalyticsYearsTable::class];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
