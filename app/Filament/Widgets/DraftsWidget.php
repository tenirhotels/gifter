<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Models\Certificate;
use App\Support\DashboardQueries;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Незавершённые продажи: что оплачено, что нет, что можно выпускать. */
class DraftsWidget extends TableWidget
{
    protected static ?int $sort = 11;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Незавершённые продажи (черновики)';

    public static function canView(): bool
    {
        $user = auth()->user();

        return (bool) $user?->hasRole(Role::Sales, Role::SalesManager) && (new DashboardQueries($user))->drafts()->whereNull('batch_id')->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => (new DashboardQueries(auth()->user()))->drafts()->whereNull('batch_id')->with(['customer', 'package'])
                ->withSum(['payments as paid_sum' => fn ($q) => $q->where('status', 'paid')], 'amount'))
            ->defaultSort('id', 'desc')
            ->paginated([5, 10, 25])->defaultPaginationPageOption(5)
            ->emptyStateHeading('Незавершённых продаж нет')
            ->recordUrl(fn (Certificate $record) => CertificateResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('number')->label('Номер'),
                TextColumn::make('package.name')->label('Пакет'),
                TextColumn::make('customer.full_name')->label('Заказчик')->placeholder('—'),
                TextColumn::make('total_amount')->label('Стоимость')->formatStateUsing(fn ($state) => Money::format($state)),
                TextColumn::make('paid_sum')->label('Оплачено')->default(0)->formatStateUsing(fn ($state) => Money::format($state))
                    ->color(fn (Certificate $record) => Money::toMinor($record->paid_sum) >= Money::toMinor($record->payable_amount) && $record->payable_amount > 0 ? 'success' : 'warning'),
                TextColumn::make('kind')->label('Тип')->badge(),
                TextColumn::make('created_at')->label('Создан')->date('d.m.Y'),
            ]);
    }
}
