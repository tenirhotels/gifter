<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Support\CommissionPeriod;
use App\Filament\Support\TranslatesPageLabels;
use App\Models\Certificate;
use App\Models\User;
use App\Services\CommissionManager;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * «Комиссии» — сводка по продавцам для Финансов и директора: продано, погашено, не погашено,
 * без брони, начислено, ожидает выплаты, выплачено. «Сертификаты» раскрывают сумму до отдельных сертификатов.
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
        $rows = collect(app(CommissionManager::class)->summary());
        $toDo = auth()->user()?->can(Permission::PayCommissions) ? $rows->sum('approved_minor') : $rows->sum('to_approve_minor');

        return $toDo > 0 ? '●' : null;
    }

    /** @return array<string, int> */
    private function row(int $sellerId): array
    {
        [$from, $to] = CommissionPeriod::range($this->getTableFilterState('period'));
        $this->rows ??= app(CommissionManager::class)->summary(null, $from, $to);

        return $this->rows[$sellerId] ?? ['sold' => 0, 'sales_minor' => 0, 'redeemed' => 0, 'not_redeemed' => 0, 'no_reservation' => 0, 'expired' => 0,
            'earned_minor' => 0, 'pending_minor' => 0, 'to_approve_minor' => 0, 'approved_minor' => 0, 'paid_minor' => 0];
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
                    ->description(fn (User $record) => ($r = $this->row($record->id))['expired'] ? "истекло: {$r['expired']}" : null),
                TextColumn::make('no_reservation')->label('Без брони')->state(fn (User $record) => $this->row($record->id)['no_reservation'])
                    ->color(fn (User $record) => $this->row($record->id)['no_reservation'] > 0 ? 'warning' : null),
                TextColumn::make('earned')->label('Начислено')->state(fn (User $record) => $money($this->row($record->id)['earned_minor']))->weight('bold'),
                TextColumn::make('pending')->label('К выплате')
                    ->state(fn (User $record) => $money($this->row($record->id)['pending_minor']))
                    ->description(fn (User $record) => ($r = $this->row($record->id))['pending_minor']
                        ? 'согласовать: '.$money($r['to_approve_minor']).' · выплатить: '.$money($r['approved_minor']) : null)
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
            ->emptyStateHeading('Продаж с комиссией пока нет');
    }
}
