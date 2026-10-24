<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Enums\ReservationReview;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Support\RunsDomainActions;
use App\Filament\Support\TranslatesPageLabels;
use App\Models\Certificate;
use App\Services\ReservationManager;
use App\Support\CertificateNumber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Проверка броней Финансами (фаза 6): имя в брони не совпало ни с получателем, ни с
 * заказчиком, или бронь отмечена в последние дни срока. Оспоренную бронь погасить нельзя.
 */
class ReservationReviews extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected string $view = 'filament.pages.upcoming-arrivals';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Деньги';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Проверка броней';

    protected static ?string $title = 'Проверка броней';

    protected static ?string $slug = 'reservation-reviews';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permission::ReviewReservations);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Certificate::query()->where('reservation_review_status', ReservationReview::Pending)->count();

        return $count ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        $manager = fn (): ReservationManager => app(ReservationManager::class);

        return $table
            ->query(fn () => Certificate::query()->whereNotNull('reservation_review_status')->with(['recipient', 'customer', 'reservationReviewer']))
            ->columns([
                TextColumn::make('number')->label('Сертификат')->searchable(query: CertificateNumber::searchQuery())
                    ->url(fn (Certificate $record) => CertificateResource::getUrl('view', ['record' => $record]))
                    ->description(fn (Certificate $record) => __(':p1 · до :p2', ['p1' => $record->status->getLabel(), 'p2' => $record->valid_until->format('d.m.Y')])),
                TextColumn::make('pms_booking_number')->label('Номер брони')->searchable()
                    ->description(fn (Certificate $record) => $record->arrival_date?->format('d.m').'–'.$record->departure_date?->format('d.m.Y')),
                TextColumn::make('booking_guest_name')->label('Гость в брони')
                    ->description(fn (Certificate $record) => $record->name_match?->shortLabel())
                    ->color(fn (Certificate $record) => $record->name_match?->getColor()),
                TextColumn::make('recipient.full_name')->label('Получатель')->placeholder('—')
                    ->description(fn (Certificate $record) => $record->customer?->displayName() ? __('заказчик: :p1', ['p1' => $record->customer->displayName()]) : null),
                TextColumn::make('reservation_note')->label('Причина')->wrap()->limit(90)->placeholder('—'),
                TextColumn::make('reservation_review_status')->label('Проверка')->badge()->toggleable(isToggledHiddenByDefault: true)
                    ->description(fn (Certificate $record) => $record->reservationReviewer?->name),
            ])
            ->filters([
                SelectFilter::make('reservation_review_status')->label('Проверка')->options(ReservationReview::class)
                    ->default(ReservationReview::Pending->value),
            ])
            ->recordActions([
                Action::make('approve')->label('Подтвердить')->icon('heroicon-o-shield-check')->color('success')
                    ->authorize(Permission::ReviewReservations)
                    ->visible(fn (Certificate $record) => in_array($record->reservation_review_status, [ReservationReview::Pending, ReservationReview::Disputed], true))
                    ->schema([Textarea::make('comment')->label('Комментарий')])
                    ->action(fn (Certificate $record, array $data, Action $action) => RunsDomainActions::run($action,
                        fn () => $manager()->approve($record, $data['comment'] ?? null), __('Бронь подтверждена'))),
                Action::make('dispute')->label('Оспорить')->icon('heroicon-o-shield-exclamation')->color('danger')
                    ->authorize(Permission::ReviewReservations)
                    ->visible(fn (Certificate $record) => in_array($record->reservation_review_status, [ReservationReview::Pending, ReservationReview::Approved], true)
                        && ! $record->status->isFinal())
                    ->modalDescription(__('Пока спор не снят, сертификат нельзя отметить использованным.'))
                    ->schema([Textarea::make('comment')->label('Что не так с бронью')->required()])
                    ->action(fn (Certificate $record, array $data, Action $action) => RunsDomainActions::run($action,
                        fn () => $manager()->dispute($record, $data['comment']), __('Бронь оспорена'))),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderBy('arrival_date'))
            ->emptyStateHeading(__('Броней на проверке нет'));
    }
}
