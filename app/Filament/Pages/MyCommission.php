<?php

namespace App\Filament\Pages;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\Permission;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Widgets\MyCommissionWidget;
use App\Models\Certificate;
use App\Support\Money;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** «Моя мотивация»: мои проданные сертификаты и комиссия по каждому. Только свои данные. */
class MyCommission extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Моя мотивация';

    protected static ?string $title = 'Моя мотивация';

    protected static ?string $slug = 'my-commission';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permission::ViewOwnCommissions);
    }

    protected function getHeaderWidgets(): array
    {
        return [MyCommissionWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Certificate::query()->where('sold_by', auth()->id())->where('kind', CertificateKind::Sale)->whereNotNull('issued_at')
                ->with(['commission', 'recipient', 'package']))
            ->columns([
                TextColumn::make('number')->label('Сертификат')->searchable()
                    ->url(fn (Certificate $record) => CertificateResource::getUrl('view', ['record' => $record]))
                    ->description(fn (Certificate $record) => $record->package?->name),
                TextColumn::make('sold_at')->label('Продан')->date('d.m.Y')->sortable(),
                TextColumn::make('total_amount')->label('Сумма')->formatStateUsing(fn ($state) => Money::format($state))->sortable(),
                TextColumn::make('status')->label('Статус')->badge(),
                TextColumn::make('reservation')->label('Бронь')
                    ->state(fn (Certificate $record) => $record->hasReservation() ? $record->reservationLabel()
                        : ($record->status === CertificateStatus::Expired ? ($record->hasPendingException() ? 'бронь по исключению ждёт директора' : 'истёк — бронь по исключению')
                            : ($record->status->isFinal() ? '—' : 'нет брони')))
                    ->color(fn (Certificate $record) => ! $record->hasReservation() && $record->status === CertificateStatus::Active ? 'warning' : null)
                    ->wrap(),
                TextColumn::make('commission.amount')->label('Комиссия')->placeholder('—')
                    ->formatStateUsing(fn ($state) => Money::format($state))->weight('bold')
                    ->description(fn (Certificate $record) => $record->commission?->ruleLabel()),
                TextColumn::make('commission.status')->label('Статус комиссии')->badge()->placeholder('не предусмотрена'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус сертификата')->options(CertificateStatus::class)->multiple(),
                Filter::make('no_reservation')->label('Активные без брони')
                    ->query(fn (Builder $query) => $query->where('status', CertificateStatus::Active)->whereNull('pms_booking_number')),
            ])
            ->defaultSort('sold_at', 'desc')
            ->emptyStateHeading('Выпущенных продаж пока нет')
            ->emptyStateDescription('Комиссия начисляется, когда сертификат погашен.');
    }
}
