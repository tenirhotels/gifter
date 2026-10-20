<?php

namespace App\Filament\Pages;

use App\Enums\ExceptionStatus;
use App\Enums\Permission;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Support\RunsDomainActions;
use App\Filament\Support\TranslatesPageLabels;
use App\Models\ReservationException;
use App\Services\ReservationExceptionManager;
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
use UnitEnum;

/**
 * Брони по исключению для истёкших сертификатов: заявки Front Office и решения директора по продажам.
 * Заявку оформляют в карточке истёкшего сертификата.
 */
class ReservationExceptions extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Брони по исключению';

    protected static ?string $title = 'Брони по исключению';

    protected static ?string $slug = 'reservation-exceptions';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can(Permission::ApproveReservationException) || $user?->can(Permission::RequestReservationException));
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ReservationException::query()->where('status', ExceptionStatus::Pending)->count();

        return $count ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        $manager = fn (): ReservationExceptionManager => app(ReservationExceptionManager::class);

        return $table
            ->query(fn () => ReservationException::query()->with(['certificate.recipient', 'certificate.seller', 'requester', 'decider']))
            ->columns([
                TextColumn::make('certificate.number')->label('Сертификат')->searchable()
                    ->url(fn (ReservationException $record) => CertificateResource::getUrl('view', ['record' => $record->certificate_id]))
                    ->description(fn (ReservationException $record) => __('истёк :p1 · продавец: :p2', ['p1' => $record->certificate->valid_until->format('d.m.Y'), 'p2' => $record->certificate->seller?->name ?? '—'])),
                TextColumn::make('booking_number')->label('Номер брони')->searchable()
                    ->description(fn (ReservationException $record) => $record->arrival_date->format('d.m').'–'.$record->departure_date->format('d.m.Y')),
                TextColumn::make('guest_name')->label('Гость')->description(fn (ReservationException $record) => __('получатель: :p1', ['p1' => $record->certificate->recipient?->full_name ?? '—'])),
                TextColumn::make('reason')->label('Причина')->wrap()->limit(120),
                TextColumn::make('requester.name')->label('Заявку подал')->description(fn (ReservationException $record) => $record->created_at?->format('d.m.Y H:i')),
                TextColumn::make('status')->label('Решение')->badge()
                    ->description(fn (ReservationException $record) => $record->decider ? $record->decider->name.($record->decision_comment ? ': '.$record->decision_comment : '') : null),
            ])
            ->filters([SelectFilter::make('status')->label('Решение')->options(ExceptionStatus::class)->default(ExceptionStatus::Pending->value)])
            ->recordActions([
                Action::make('approve')->label('Согласовать')->icon('heroicon-o-hand-thumb-up')->color('success')
                    ->authorize(Permission::ApproveReservationException)
                    ->visible(fn (ReservationException $record) => $record->status === ExceptionStatus::Pending)
                    ->modalDescription(__('Сертификат вернётся в работу до даты выезда, бронь привяжется; комиссия продавца начислится при использовании сертификата.'))
                    ->schema([Textarea::make('comment')->label('Комментарий')])
                    ->action(fn (ReservationException $record, array $data, Action $action) => RunsDomainActions::run($action,
                        fn () => $manager()->approve($record, $data['comment'] ?? null), __('Исключение согласовано'))),
                Action::make('reject')->label('Отклонить')->icon('heroicon-o-hand-thumb-down')->color('danger')
                    ->authorize(Permission::ApproveReservationException)
                    ->visible(fn (ReservationException $record) => $record->status === ExceptionStatus::Pending)
                    ->schema([Textarea::make('comment')->label('Причина отказа')->required()])
                    ->action(fn (ReservationException $record, array $data, Action $action) => RunsDomainActions::run($action,
                        fn () => $manager()->reject($record, $data['comment']), __('Заявка отклонена'))),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('Заявок нет'))
            ->emptyStateDescription(__('Заявку оформляют в карточке истёкшего сертификата: «Бронь по исключению».'));
    }
}
