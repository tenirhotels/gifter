<?php

namespace App\Filament\Resources\Certificates\Schemas;

use App\Enums\Permission;
use App\Models\Certificate;
use App\Services\CertificateDocument;
use App\Support\ContactMask;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class CertificateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Сертификат')->columns(2)->columnSpan(2)->schema([
                TextEntry::make('number')->label('Номер')->copyable()->weight('bold'),
                TextEntry::make('status')->label('Статус')->badge(),
                TextEntry::make('kind')->label('Тип')->badge(),
                TextEntry::make('complimentary_reason')->label('Основание')->visible(fn (Certificate $record) => $record->isComplimentary())
                    ->helperText(fn (Certificate $record) => collect([$record->complimentary_purpose?->getLabel(), $record->batch?->shortLabel()])->filter()->implode(' · ') ?: null),
                TextEntry::make('complimentary_approval')->label('Согласование директора')
                    ->visible(fn (Certificate $record) => $record->isComplimentary())
                    ->state(fn (Certificate $record) => $record->isComplimentaryApproved()
                        ? $record->complimentaryApprover?->name.', '.$record->complimentary_approved_at->format('d.m.Y H:i')
                        : 'ждёт согласования')
                    ->color(fn (Certificate $record) => $record->isComplimentaryApproved() ? 'success' : 'warning'),
                TextEntry::make('pending_refund')->label('Внимание')->color('danger')->columnSpanFull()
                    ->state('Запрос на возврат ждёт решения Финансов — действия с сертификатом заблокированы.')
                    ->visible(fn (Certificate $record) => $record->hasPendingRefund()),
                TextEntry::make('customer_name')->label('Заказчик')->placeholder('—')
                    ->state(fn (Certificate $record) => $record->customer?->displayName())
                    ->helperText(fn (Certificate $record) => self::maskedContacts($record->customer)),
                TextEntry::make('recipient.full_name')->label('Получатель')->placeholder('не указан')
                    ->helperText(fn (Certificate $record) => self::maskedContacts($record->recipient)),
                TextEntry::make('sold_at')->label('Дата продажи')->date('d.m.Y'),
                TextEntry::make('validity')->label('Срок действия')
                    ->state(fn (Certificate $record) => $record->valid_from->format('d.m.Y').' — '.$record->valid_until->format('d.m.Y'))
                    ->helperText(fn (Certificate $record) => $record->custom_validity ? 'задан вручную при выпуске' : null),
                TextEntry::make('package.name')->label('Пакет')->placeholder('—')
                    ->helperText(fn (Certificate $record) => $record->package?->validityLabel()),
                TextEntry::make('notes')->label('Примечание')->placeholder('—'),
                TextEntry::make('verify_url')->label('Ссылка проверки (QR)')->columnSpanFull()->copyable()
                    ->state(fn (Certificate $record) => app(CertificateDocument::class)->verificationUrl($record))
                    ->helperText('Её же содержит QR-код в PDF. Страница показывает только номер, статус, срок и пакет.')
                    ->visible(fn (Certificate $record) => $record->isPrintable()),
                TextEntry::make('cancellation_reason')->label('Причина аннулирования')->visible(fn (Certificate $record) => filled($record->cancellation_reason))->color('danger'),
            ]),
            Section::make('Деньги')->columnSpan(1)->schema([
                TextEntry::make('total_amount')->label(fn (Certificate $record) => $record->isComplimentary() ? 'Стоимость по прайсу' : 'Стоимость')->state(fn (Certificate $record) => Money::format($record->total_amount))->size('lg')->weight('bold'),
                TextEntry::make('payable_amount')->label('К оплате заказчиком')->state(fn (Certificate $record) => Money::format($record->payable_amount))
                    ->helperText('Платные доп. услуги; остальное бесплатно и идёт в маркетинговый расход.')->weight('bold')
                    ->visible(fn (Certificate $record) => $record->isComplimentary() && $record->hasPayablePart()),
                TextEntry::make('total_cost')->label(fn (Certificate $record) => $record->isComplimentary() ? 'Маркетинговый расход (себестоимость)' : 'Себестоимость')
                    ->state(fn (Certificate $record) => Money::format($record->isComplimentary() ? $record->marketing_cost : $record->total_cost))
                    ->helperText(fn (Certificate $record) => $record->hasUnknownCost() ? 'У части услуг себестоимость не указана — сумма неполная.' : null)
                    ->color(fn (Certificate $record) => $record->hasUnknownCost() ? 'warning' : null)
                    ->visible(fn () => auth()->user()?->can(Permission::ViewCosts)),
                TextEntry::make('margin')->label('Маржа')
                    ->state(fn (Certificate $record) => Money::format(Money::fromMinor(Money::toMinor($record->total_amount) - Money::toMinor($record->total_cost))))
                    ->visible(fn (Certificate $record) => ! $record->isComplimentary() && auth()->user()?->can(Permission::ViewCosts)),
                TextEntry::make('paid')->label('Оплачено')->hidden(fn (Certificate $record) => $record->isComplimentary() && ! $record->hasPayablePart())->state(fn (Certificate $record) => Money::format($record->paidAmount()))
                    ->color(fn (Certificate $record) => $record->isFullyPaid() && $record->hasPayablePart() ? 'success' : 'warning'),
                TextEntry::make('invoiced')->label('Выставлено счетов')->hidden(fn (Certificate $record) => $record->isComplimentary() && ! $record->hasPayablePart())->state(fn (Certificate $record) => Money::format($record->invoicedAmount())),
                TextEntry::make('creator.name')->label('Создал')->placeholder('—')
                    ->helperText(fn (Certificate $record) => $record->created_at?->format('d.m.Y H:i')),
                TextEntry::make('seller.name')->label('Продавец')->placeholder('—')
                    ->helperText('Комиссия закреплена за продавцом навсегда после выпуска.')
                    ->visible(fn (Certificate $record) => ! $record->isComplimentary()),
                TextEntry::make('commission_info')->label('Комиссия продавца')
                    ->state(fn (Certificate $record) => $record->commission
                        ? Money::format($record->commission->amount).' · '.$record->commission->status->getLabel()
                        : 'не предусмотрена')
                    ->helperText(fn (Certificate $record) => $record->commission ? $record->commission->ruleLabel().' от '.Money::format($record->commission->sale_price).' — правило на дату продажи' : null)
                    ->visible(fn (Certificate $record) => ! $record->isComplimentary() && ! $record->isDraft()
                        && (auth()->user()?->can(Permission::ViewAllCommissions) || $record->sold_by === auth()->id())),
                TextEntry::make('issued_at')->label('Выпущен')->dateTime('d.m.Y H:i')->placeholder('ещё не выпущен'),
            ]),
            // Бронь (фаза 6): номер PMS, гость, даты, сверка имени и проверка Финансов.
            Section::make('Бронь')->icon('heroicon-o-calendar-days')->columns(4)->columnSpanFull()
                ->visible(fn (Certificate $record) => $record->hasReservation())
                ->schema([
                    TextEntry::make('pms_booking_number')->label('Номер брони PMS')->weight('bold')->copyable()
                        ->helperText(fn (Certificate $record) => $record->pms_verified_at
                            ? 'Подтверждена по PMS '.$record->pms_verified_at->format('d.m.Y H:i').($record->pms_data_age_minutes ? " (данным PMS {$record->pms_data_age_minutes} мин)" : '')
                            : 'Внесена вручную, не подтверждена по PMS'),
                    TextEntry::make('stay')->label('Проживание')
                        ->state(fn (Certificate $record) => $record->arrival_date?->format('d.m.Y').' — '.$record->departure_date?->format('d.m.Y'))
                        ->helperText(fn (Certificate $record) => $record->arrival_date ? $record->arrival_date->diffInDays($record->departure_date).' ноч.' : null),
                    TextEntry::make('booking_guest_name')->label('Гость в брони')
                        ->helperText(fn (Certificate $record) => $record->name_match?->getLabel()),
                    TextEntry::make('reservation_review_status')->label('Проверка Финансов')->badge()
                        ->visible(fn (Certificate $record) => $record->reservation_review_status !== null)
                        ->helperText(fn (Certificate $record) => collect([
                            $record->reservationReviewer?->name, $record->reservation_reviewed_at?->format('d.m.Y H:i'), $record->reservation_review_comment,
                        ])->filter()->implode(' · ') ?: null),
                    TextEntry::make('reservation_note')->label('Причина / комментарий')->columnSpanFull()
                        ->visible(fn (Certificate $record) => filled($record->reservation_note)),
                ]),
        ]);
    }

    private static function maskedContacts(?Model $person): ?string
    {
        return $person ? (collect([ContactMask::phone($person->phone), ContactMask::email($person->email)])->filter()->implode(', ') ?: null) : null;
    }
}
