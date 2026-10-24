<?php

namespace App\Filament\Resources\Certificates\Schemas;

use App\Enums\CommissionStatus;
use App\Enums\CommissionType;
use App\Enums\Permission;
use App\Filament\Resources\OnlineOrders\OnlineOrderResource;
use App\Models\Certificate;
use App\Models\CertificateCommission;
use App\Services\CertificateDocument;
use App\Support\ContactMask;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class CertificateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Сертификат')->columns(2)->columnSpan(2)->schema([
                TextEntry::make('number')->label('Номер')->copyable()->weight('bold'),
                TextEntry::make('serial_number')->label('Порядковый № в системе')->placeholder('—')->helperText(__('Внутренний номер для реестра и отчётов; гостю не показывается.')),
                TextEntry::make('status')->label('Статус')->badge(),
                TextEntry::make('kind')->label('Вид выпуска')->badge(),
                TextEntry::make('source')->label('Канал')->badge()
                    ->helperText(fn (Certificate $record) => $record->online_order_id ? __('Заявка с сайта № :p1', ['p1' => $record->online_order_id]) : null)
                    ->url(fn (Certificate $record) => $record->online_order_id && auth()->user()?->can(Permission::ProcessOnlineOrders) ? OnlineOrderResource::getUrl('index') : null),
                TextEntry::make('complimentary_reason')->label('Основание')->visible(fn (Certificate $record) => $record->isComplimentary())
                    ->helperText(fn (Certificate $record) => collect([$record->complimentary_purpose?->getLabel(), $record->batch?->shortLabel()])->filter()->implode(' · ') ?: null),
                TextEntry::make('complimentary_approval')->label('Согласование директора')
                    ->visible(fn (Certificate $record) => $record->isComplimentary())
                    ->state(fn (Certificate $record) => $record->isComplimentaryApproved()
                        ? $record->complimentaryApprover?->name.', '.$record->complimentary_approved_at->format('d.m.Y H:i')
                        : __('ждёт согласования'))
                    ->color(fn (Certificate $record) => $record->isComplimentaryApproved() ? 'success' : 'warning'),
                TextEntry::make('pending_refund')->label('Внимание')->color('danger')->columnSpanFull()
                    ->state(__('Запрос на возврат ждёт решения Финансов — действия с сертификатом заблокированы.'))
                    ->visible(fn (Certificate $record) => $record->hasPendingRefund()),
                TextEntry::make('customer_name')->label('Заказчик')->placeholder('—')
                    ->state(fn (Certificate $record) => $record->customer?->displayName())
                    ->helperText(fn (Certificate $record) => self::maskedContacts($record->customer)),
                TextEntry::make('recipient.full_name')->label('Получатель')->placeholder(__('Не указан'))
                    ->helperText(fn (Certificate $record) => self::maskedContacts($record->recipient)),
                TextEntry::make('sold_at')->label('Дата продажи')->date('d.m.Y'),
                TextEntry::make('validity')->label('Срок действия')
                    ->state(fn (Certificate $record) => $record->valid_from->format('d.m.Y').' — '.$record->valid_until->format('d.m.Y'))
                    ->helperText(fn (Certificate $record) => $record->custom_validity ? __('задан вручную при выпуске') : null),
                TextEntry::make('package.name')->label('Пакет сертификата')->placeholder('—')
                    ->helperText(fn (Certificate $record) => $record->package?->validityLabel()),
                TextEntry::make('notes')->label('Комментарий')->placeholder('—'),
                TextEntry::make('verify_url')->label('Ссылка проверки сертификата')->columnSpanFull()->copyable()
                    ->state(fn (Certificate $record) => app(CertificateDocument::class)->verificationUrl($record))
                    ->helperText(__('Эта ссылка зашита в QR-код на PDF. Гость увидит только номер, статус, срок и пакет.'))
                    ->visible(fn (Certificate $record) => $record->isPrintable()),
                TextEntry::make('cancellation_reason')->label('Причина аннулирования')->visible(fn (Certificate $record) => filled($record->cancellation_reason))->color('danger'),
            ]),
            Section::make('Стоимость и комиссия')->columnSpan(1)->schema([
                TextEntry::make('total_amount')->label(fn (Certificate $record) => $record->isComplimentary() ? __('Стоимость по прайсу') : __('Стоимость сертификата'))->state(fn (Certificate $record) => Money::format($record->total_amount))->size('lg')->weight('bold'),
                TextEntry::make('payable_amount')->label('К оплате заказчиком')->state(fn (Certificate $record) => Money::format($record->payable_amount))
                    ->helperText(__('Только платные допуслуги; остальное бесплатно (маркетинговый расход).'))->weight('bold')
                    ->visible(fn (Certificate $record) => $record->isComplimentary() && $record->hasPayablePart()),
                TextEntry::make('total_cost')->label(fn (Certificate $record) => $record->isComplimentary() ? __('Маркетинговый расход (себестоимость)') : __('Себестоимость'))
                    ->state(fn (Certificate $record) => Money::format($record->isComplimentary() ? $record->marketing_cost : $record->total_cost))
                    ->helperText(fn (Certificate $record) => $record->hasUnknownCost() ? __('У части услуг себестоимость не указана — сумма неполная.') : null)
                    ->color(fn (Certificate $record) => $record->hasUnknownCost() ? 'warning' : null)
                    ->visible(fn () => auth()->user()?->can(Permission::ViewCosts)),
                TextEntry::make('margin')->label('Маржа')
                    ->state(fn (Certificate $record) => Money::format(Money::fromMinor(Money::toMinor($record->total_amount) - Money::toMinor($record->total_cost))))
                    ->visible(fn (Certificate $record) => ! $record->isComplimentary() && auth()->user()?->can(Permission::ViewCosts)),
                TextEntry::make('paid')->label('Получено оплаты')->helperText(fn (Certificate $record) => $record->isDraft() && $record->isFullyPaid() && ! $record->isPaymentConfirmed() ? __('Ждёт подтверждения Финансов — после сверки можно выпустить') : null)->hidden(fn (Certificate $record) => $record->isComplimentary() && ! $record->hasPayablePart())->state(fn (Certificate $record) => Money::format($record->paidAmount()))
                    ->color(fn (Certificate $record) => $record->isFullyPaid() && $record->hasPayablePart() ? 'success' : 'warning'),
                TextEntry::make('invoiced')->label('Выставлено счетов')->hidden(fn (Certificate $record) => $record->isComplimentary() && ! $record->hasPayablePart())->state(fn (Certificate $record) => Money::format($record->invoicedAmount())),
                TextEntry::make('creator.name')->label('Оформил')->placeholder('—')
                    ->helperText(fn (Certificate $record) => $record->created_at?->format('d.m.Y H:i')),
                TextEntry::make('seller.name')->label('Ответственный за продажу')->placeholder('—')
                    ->helperText(__('Комиссия закреплена за продавцом навсегда после выпуска.'))
                    ->visible(fn (Certificate $record) => ! $record->isComplimentary()),
                TextEntry::make('commission_info')->label('Комиссия за продажу')
                    ->state(fn (Certificate $record) => $record->commission
                        ? __('Комиссия').': '.Money::format($record->commission->amount)
                        : __('Комиссия по этому пакету не предусмотрена'))
                    ->helperText(fn (Certificate $record) => $record->commission ? self::commissionNote($record->commission) : null)
                    ->visible(fn (Certificate $record) => ! $record->isComplimentary() && ! $record->isDraft()
                        && (auth()->user()?->can(Permission::ViewAllCommissions) || $record->sold_by === auth()->id())),
                TextEntry::make('issued_at')->label('Дата выпуска')->dateTime('d.m.Y H:i')->placeholder(__('ещё не выпущен')),
            ]),
            // Бронь (фаза 6): номер PMS, гость, даты, сверка имени и проверка Финансов.
            Section::make('Бронь')->icon('heroicon-o-calendar-days')->columns(4)->columnSpanFull()
                ->visible(fn (Certificate $record) => $record->hasReservation())
                ->schema([
                    TextEntry::make('pms_booking_number')->label('Номер брони PMS')->weight('bold')->copyable()
                        ->helperText(fn (Certificate $record) => $record->pms_verified_at
                            ? __('Подтверждена по PMS :p1', ['p1' => $record->pms_verified_at->format('d.m.Y H:i')]).($record->pms_data_age_minutes ? ' '.__('(данным PMS :p1 мин)', ['p1' => $record->pms_data_age_minutes]) : '')
                            : __('Внесена вручную, не подтверждена по PMS'))
                        ->belowContent(fn (Certificate $record) => $record->pms_checked_at
                            ? __('Проверка PMS').' '.$record->pms_checked_at->format('d.m.Y H:i').match ($record->pms_check_status) {
                                'ok' => ': '.__('без изменений'),
                                'changed' => ': '.__('обновлено по PMS — :p1', ['p1' => $record->pms_check_note]),
                                'cancelled', 'discrepancy' => ': '.__('расхождение — :p1', ['p1' => $record->pms_check_note]),
                                'unavailable' => ': '.__('PMS недоступна'),
                                default => '',
                            } : null),
                    TextEntry::make('stay')->label('Проживание')
                        ->state(fn (Certificate $record) => $record->arrival_date?->format('d.m.Y').' — '.$record->departure_date?->format('d.m.Y'))
                        ->helperText(fn (Certificate $record) => $record->arrival_date ? __(':p1 ноч.', ['p1' => $record->arrival_date->diffInDays($record->departure_date)]) : null),
                    TextEntry::make('booking_guest_name')->label('Гость в брони')
                        ->helperText(fn (Certificate $record) => $record->name_match?->getLabel()),
                    TextEntry::make('reservation_review_status')->label('Проверка Финансов')->badge()
                        ->visible(fn (Certificate $record) => $record->reservation_review_status !== null)
                        ->helperText(fn (Certificate $record) => collect([
                            $record->reservationReviewer?->name, $record->reservation_reviewed_at?->format('d.m.Y H:i'), $record->reservation_review_comment,
                        ])->filter()->implode(' · ') ?: null),
                    TextEntry::make('reservation_note')->label('Причина или комментарий')->columnSpanFull()
                        ->visible(fn (Certificate $record) => filled($record->reservation_note)),
                ]),
        ]);
    }

    /** Состояние комиссии простыми словами: статус, пояснение и правило на дату продажи. */
    private static function commissionNote(CertificateCommission $commission): HtmlString
    {
        $status = match ($commission->status) {
            CommissionStatus::PendingRedemption => __('Ожидает использования сертификата'),
            CommissionStatus::Earned => __('Комиссия начислена'),
            CommissionStatus::Approved => __('К выплате'),
            CommissionStatus::Paid => __('Выплачена'),
            default => __('Не начислена'),
        };
        $rule = $commission->rule_type === CommissionType::Percent
            ? __('Комиссия').' '.rtrim(rtrim((string) $commission->rule_rate, '0'), '.').'% '.__('от').' '.Money::format($commission->sale_price)
            : __('Фиксированная комиссия').' '.Money::format($commission->rule_fixed_amount);
        $lines = [e($status)];
        if ($commission->status === CommissionStatus::PendingRedemption) {
            $lines[] = e(__('Комиссия будет начислена после использования сертификата'));
        }
        $lines[] = e($rule.' · '.__('правило на дату продажи'));

        return new HtmlString(implode('<br>', $lines));
    }

    private static function maskedContacts(?Model $person): ?string
    {
        return $person ? (collect([ContactMask::phone($person->phone), ContactMask::email($person->email)])->filter()->implode(', ') ?: null) : null;
    }
}
