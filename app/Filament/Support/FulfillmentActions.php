<?php

namespace App\Filament\Support;

use App\Enums\CertificateStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\Permission;
use App\Models\ServiceFulfillment;
use App\Services\FulfillmentManager;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                ->fillForm(fn (ServiceFulfillment $record) => ['request_details' => self::requestTemplate($record)])
                ->schema([Textarea::make('request_details')->label('Текст заявки поставщику')->rows(5)
                    ->helperText('Отправьте поставщику удобным способом (телефон, мессенджер, почта) и сохраните — текст останется в заявке.')])
                ->action(fn (ServiceFulfillment $record, array $data, Action $action) => $run($action,
                    fn () => $manager()->request($record, $data['request_details'] ?? null), 'Заявка отмечена как отправленная')),

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
                Action::make('cancelFulfillment')->label('Отменить')->icon('heroicon-o-x-mark')->color('danger')
                    ->authorize(Permission::ManageFulfillments)
                    ->visible(fn (ServiceFulfillment $record) => $record->status->isOpen())
                    ->schema([Textarea::make('reason')->label('Причина')->required()])
                    ->action(fn (ServiceFulfillment $record, array $data, Action $action) => $run($action,
                        fn () => $manager()->cancel($record, $data['reason']), 'Заявка отменена')),
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
                TextInput::make('time')->label('Время')->placeholder('19:00')->mask('99:99')->maxLength(5)
                    ->required(fn (ServiceFulfillment $record) => $record->requires_scheduling),
                Textarea::make('note')->label('Комментарий'),
            ])
            ->action(fn (ServiceFulfillment $record, array $data, Action $action) => $run($action,
                fn () => app(FulfillmentManager::class)->schedule($record, Carbon::parse($data['date']), $data['time'] ?? null, $data['note'] ?? null), 'Время назначено'));
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
                ->sortable(query: fn ($query, string $direction) => $query->orderBy('scheduled_date', $direction)->orderBy('scheduled_time', $direction)),
            TextColumn::make('updated_at')->label('Изменено')->dateTime('d.m.Y H:i')->description(fn (ServiceFulfillment $record) => $record->updater?->name)->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('request_details')->label('Заявка')->placeholder('—')->wrap()->limit(80)->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('notes')->label('Комментарии')->placeholder('—')->wrap()->limit(80)
                ->state(fn (ServiceFulfillment $record) => $record->cancellation_reason ? 'Отмена: '.$record->cancellation_reason : $record->notes)->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /** Текст заявки по умолчанию: номер, гость, услуга, когда. */
    public static function requestTemplate(ServiceFulfillment $record): string
    {
        $certificate = $record->certificate;

        return collect([
            "Сертификат {$certificate->number}",
            'Гость: '.($certificate->recipient?->full_name ?? 'уточняется'),
            'Услуга: '.$record->serviceLabel(),
            'Когда: '.($record->whenLabel() ?? 'уточняется'),
            'Сертификат действует до '.$certificate->valid_until->format('d.m.Y'),
        ])->implode("\n");
    }

    private static function providerContacts(ServiceFulfillment $record): ?string
    {
        $provider = $record->provider;
        if (! $provider) {
            return 'Поставщик у услуги не указан.';
        }

        return collect([$provider->name, $provider->contact_person ?? null, $provider->phone ?? null, $provider->email ?? null])
            ->filter()->implode(' · ').($provider->notes ? ". Условия: {$provider->notes}" : '');
    }
}
