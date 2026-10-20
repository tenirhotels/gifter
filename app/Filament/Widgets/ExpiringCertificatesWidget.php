<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Models\Certificate;
use App\Support\DashboardQueries;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Support\Htmlable;

/** Ближайшие истечения — тем, кто может связаться с гостем или принять его. */
class ExpiringCertificatesWidget extends TableWidget
{
    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Истекают в ближайшие 14 дней';

    protected function getTableHeading(): string|Htmlable|null
    {
        return static::$heading ? __(static::$heading) : null;
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        // Пустую таблицу не показываем — место на главной только под то, что требует внимания.
        return (bool) $user?->hasRole(Role::SuperAdmin, Role::Sales, Role::SalesManager, Role::FrontOffice)
            && (new DashboardQueries($user))->expiringSoon()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => (new DashboardQueries(auth()->user()))->expiringSoon()->with(['recipient', 'customer']))
            ->defaultSort('valid_until')
            ->paginated([5, 10, 25])->defaultPaginationPageOption(5)
            ->emptyStateHeading(__('Нет сертификатов с истекающим сроком'))
            ->recordUrl(fn (Certificate $record) => CertificateResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('number')->label('Номер'),
                TextColumn::make('valid_until')->label('Действует по')->date('d.m.Y')->color('danger')
                    ->description(fn (Certificate $record) => 'осталось '.max(0, (int) today()->diffInDays($record->valid_until)).' дн.'),
                TextColumn::make('status')->label('Статус')->badge(),
                TextColumn::make('recipient.full_name')->label('Получатель')->placeholder(__('не указан')),
                TextColumn::make('customer.full_name')->label('Заказчик')->placeholder('—'),
            ]);
    }
}
