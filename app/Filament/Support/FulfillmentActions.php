<?php

namespace App\Filament\Support;

use App\Enums\CertificateStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\Permission;
use App\Models\ServiceFulfillment;
use App\Services\FulfillmentManager;
use App\Support\PhoneNumber;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Carbon;

/**
 * Действия и колонки заявок на исполнение — общие для вкладки «Исполнение»
 * сертификата и очереди «Исполнение услуг».
 */
final class FulfillmentActions
{
    /**
     * @param  Closure|null  $after  что сделать после успешного действия (например, обновить страницу)
     * @return list<Action|ActionGroup>
     */
    public static function make(?Closure $after = null): array
    {
        $run = function (Action $action, callable $callback, string $success) use ($after): void {
            RunsDomainActions::run($action, $callback, $success);
            if ($after) {
                $after();
            }
        };
        $active = fn (ServiceFulfillment $record): bool => in_array($record->certificate?->status, [CertificateStatus::Reserved, CertificateStatus::InUse], true);
        $manager = fn (): FulfillmentManager => app(FulfillmentManager::class);

        return [
            Action::make('requestFulfillment')->label('Отправить заявку')->icon('heroicon-o-paper-airplane')->color('primary')
                ->authorize(Permission::ManageFulfillments)
                ->visible(fn (ServiceFulfillment $record) => $active($record) && $record->status === FulfillmentStatus::Pending)
                ->modalDescription(fn (ServiceFulfillment $record) => self::providerContacts($record))
                ->modalSubmitActionLabel('Заявка отправлена')
                ->fillForm(function (ServiceFulfillment $record) {
                    $guests = $record->guests ?? self::defaultGuests($record);

                    return ['guests' => $guests, 'location' => $record->location,
                        'request_details' => self::requestTemplate($record, $guests, $record->location)];
                })
                ->schema([
                    TextInput::make('guests')->label('Гости')->required()->maxLength(255)
                        ->helperText('Имена, как в брони; при необходимости — количество человек.')
                        ->live(onBlur: true)->afterStateUpdated(fn (Set $set, Get $get, ServiceFulfillment $record) => $set('request_details',
                            self::requestTemplate($record, $get('guests'), $get('location')))),
                    TextInput::make('location')->label('Место')->maxLength(255)
                        ->placeholder('Для трансфера — откуда забрать: аэропорт, рейс, адрес')
                        ->live(onBlur: true)->afterStateUpdated(fn (Set $set, Get $get, ServiceFulfillment $record) => $set('request_details',
                            self::requestTemplate($record, $get('guests'), $get('location')))),
                    Textarea::make('request_details')->label('Текст заявки поставщику')->rows(6)
                        ->helperText('Без номера сертификата и цен — только то, что нужно исполнителю. Отправьте удобным способом (телефон, мессенджер, почта) и сохраните — текст останется в заявке.'),
                ])
                ->action(fn (ServiceFulfillment $record, array $data, Action $action) => $run($action,
                    fn () => $manager()->request($record, $data['request_details'] ?? null, $data['guests'] ?? null, $data['location'] ?? null),
                    'Заявка отмечена как отправленная')),

            self::scheduleAction('scheduleFulfillment', 'Назначить время',
                fn (ServiceFulfillment $record) => $record->scheduled_date === null && $record->status !== FulfillmentStatus::Pending, $active, $run),

            Action::make('confirmFulfillment')->label('Подтверждено')->icon('heroicon-o-check')->color('success')
                ->authorize(Permission::ManageFulfillments)
                ->visible(fn (ServiceFulfillment $record) => $active($record) && in_array($record->status, [FulfillmentStatus::Requested, FulfillmentStatus::Scheduled], true)
                    && ! ($record->requires_scheduling && ! $record->scheduled_date))
                ->schema([Textarea::make('note')->label('Комментарий (кто подтвердил, номер брони у поставщика)')])
                ->action(fn (ServiceFulfillment $record, array $data, Action $action) => $run($action,
                    fn () => $manager()->confirm($record, $data['note'] ?? null), 'Подтверждено поставщиком')),

            Action::make('completeFulfillment')->label('Выполнено')->icon('heroicon-o-check-badge')->color('success')
                ->authorize(Permission::ManageFulfillments)
                ->visible(fn (ServiceFulfillment $record) => $record->certificate?->status === CertificateStatus::InUse
                    && in_array($record->status, [FulfillmentStatus::Requested, FulfillmentStatus::Scheduled, FulfillmentStatus::Confirmed], true))
                ->modalDescription('Отмечает, что услуга оказана гостю. Сертификат при этом не гасится — он погашается только целиком.')
                ->schema([Textarea::make('note')->label('Комментарий')])
                ->action(fn (ServiceFulfillment $record, array $data, Action $action) => $run($action,
                    fn () => $manager()->complete($record, $data['note'] ?? null), 'Услуга выполнена')),

            // Следующий шаг — в строке; перенос и отмена — в меню, чтобы строка не расползалась.
            ActionGroup::make([
                self::scheduleAction('rescheduleFulfillment', fn (ServiceFulfillment $record) => $record->scheduled_date ? 'Перенести' : 'Назначить время',
                    fn (ServiceFulfillment $record) => $record->scheduled_date !== null || $record->status === FulfillmentStatus::Pending, $active, $run),
                Action::make('revertFulfillment')
                    ->label(fn (ServiceFulfillment $record) => FulfillmentManager::revertLabel($record->status) ?? 'Отменить')
                    ->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    ->authorize(Permission::ManageFulfillments)
                    ->visible(fn (ServiceFulfillment $record) => $active($record) && FulfillmentManager::revertLabel($record->status) !== null)
                    ->modalDescription(fn (ServiceFulfillment $record) => $record->status === FulfillmentStatus::Confirmed
                        ? 'Услуга остаётся в сертификате; заявка вернётся в «'.($record->scheduled_date ? 'Время назначено' : 'Заявка отправлена').'» — подтверждение нужно получить заново.'
                        : 'Услуга остаётся в сертификате; заявка вернётся в «Ожидает», дата и время сбросятся — отправьте заявку заново (тому же или другому исполнителю).')
                    ->schema([Textarea::make('reason')->label('Причина')->required()
                        ->placeholder('Например: поставщик перенёс, гость попросил другое время')])
                    ->action(fn (ServiceFulfillment $record, array $data, Action $action) => $run($action,
                        fn () => $manager()->revert($record, $data['reason']), 'Статус заявки возвращён')),
            ])->label('Ещё')->icon('heroicon-m-ellipsis-vertical')->color('gray'),
        ];
    }

    /** Назначить время (в строке, пока даты нет) и перенести (в меню, когда дата уже есть). */
    private static function scheduleAction(string $name, string|Closure $label, Closure $when, Closure $active, Closure $run): Action
    {
        return Action::make($name)->label($label)->icon('heroicon-o-calendar')->color($name === 'scheduleFulfillment' ? 'primary' : 'gray')
            ->authorize(Permission::ManageFulfillments)
            ->visible(fn (ServiceFulfillment $record) => $active($record) && $record->status->isOpen() && $when($record))
            ->modalDescription(fn (ServiceFulfillment $record) => $record->status === FulfillmentStatus::Confirmed
                ? 'Услуга уже подтверждена: после переноса подтверждение нужно получить заново.' : null)
            ->fillForm(fn (ServiceFulfillment $record) => ['date' => $record->scheduled_date, 'time' => $record->scheduled_time])
            ->schema([
                DatePicker::make('date')->label('Дата')->required(),
                // Список времени с шагом 5 минут и поиском («19» → 19:00, 19:05…): одинаково на любом компьютере, без AM/PM.
                Select::make('time')->label('Время')->options(self::timeSlots())->searchable()->placeholder('Выберите или введите часы: 19')
                    ->required(fn (ServiceFulfillment $record) => $record->requires_scheduling),
                Textarea::make('note')->label('Комментарий'),
            ])
            ->action(fn (ServiceFulfillment $record, array $data, Action $action) => $run($action,
                fn () => app(FulfillmentManager::class)->schedule($record, Carbon::parse($data['date']), $data['time'] ?? null, $data['note'] ?? null), 'Время назначено'));
    }

    /** @return array<string, string> 00:00 … 23:55 */
    public static function timeSlots(): array
    {
        $slots = [];
        for ($minutes = 0; $minutes < 24 * 60; $minutes += 5) {
            $time = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
            $slots[$time] = $time;
        }

        return $slots;
    }

    /** @return list<TextColumn> */
    public static function columns(): array
    {
        return [
            TextColumn::make('service')->label('Услуга')->state(fn (ServiceFulfillment $record) => $record->serviceLabel())->wrap()
                ->description(fn (ServiceFulfillment $record) => ($needs = collect([
                    $record->requires_scheduling ? 'время' : null,
                    $record->requires_confirmation ? 'подтверждение' : null,
                ])->filter()->implode(', ')) ? "нужно: {$needs}" : null),
            TextColumn::make('provider.name')->label('Поставщик')->placeholder('—')->wrap(),
            TextColumn::make('status')->label('Статус')->badge(),
            TextColumn::make('when')->label('Когда')->state(fn (ServiceFulfillment $record) => $record->whenLabel())->placeholder('не назначено')
                ->description(fn (ServiceFulfillment $record) => $record->location)->wrap()
                ->sortable(query: fn ($query, string $direction) => $query->orderBy('scheduled_date', $direction)->orderBy('scheduled_time', $direction)),
            TextColumn::make('updated_at')->label('Изменено')->dateTime('d.m.Y H:i')->description(fn (ServiceFulfillment $record) => $record->updater?->name)->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('request_details')->label('Заявка')->placeholder('—')->wrap()->limit(80)->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('notes')->label('Комментарии')->placeholder('—')->wrap()->limit(80)
                ->state(fn (ServiceFulfillment $record) => $record->cancellation_reason ? 'Отмена: '.$record->cancellation_reason : $record->notes)->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /** Гости по умолчанию — имя из брони PMS, иначе получатель. */
    public static function defaultGuests(ServiceFulfillment $record): ?string
    {
        return $record->certificate->booking_guest_name ?? $record->certificate->recipient?->full_name;
    }

    /**
     * Текст заявки исполнителю: только то, что нужно для услуги — гости, даты проживания,
     * когда и где. Номер сертификата, срок и цены поставщику не нужны.
     */
    public static function requestTemplate(ServiceFulfillment $record, ?string $guests = null, ?string $location = null): string
    {
        $certificate = $record->certificate;
        $stay = $certificate->arrival_date
            ? 'Проживание: заезд '.$certificate->arrival_date->format('d.m.Y').($certificate->departure_date ? ', выезд '.$certificate->departure_date->format('d.m.Y') : '')
            : null;

        return collect([
            'Заявка от '.config('gifter.certificate.hotel_name'),
            'Услуга: '.$record->serviceLabel(),
            'Гости: '.(filled($guests) ? $guests : 'уточняется'),
            $stay,
            'Когда: '.($record->whenLabel() ?? 'уточним'),
            filled($location) ? "Место: {$location}" : null,
            filled(config('gifter.certificate.hotel_contacts')) ? 'Контакты отеля: '.config('gifter.certificate.hotel_contacts') : null,
        ])->filter()->implode("\n");
    }

    private static function providerContacts(ServiceFulfillment $record): ?string
    {
        $provider = $record->provider;
        if (! $provider) {
            return 'Поставщик у услуги не указан.';
        }

        return collect([$provider->name, $provider->contact_person ?? null, PhoneNumber::format($provider->phone), $provider->email ?? null])
            ->filter()->implode(' · ').($provider->notes ? ". Условия: {$provider->notes}" : '');
    }
}
