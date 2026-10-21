<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum HistoryEvent: string implements HasLabel
{
    case Created = 'created';
    case Updated = 'updated';
    case RecipientChanged = 'recipient_changed';
    case ItemAdded = 'item_added';
    case ItemChanged = 'item_changed';
    case ItemRemoved = 'item_removed';
    case PackageApplied = 'package_applied';
    case Repriced = 'repriced';
    case PaymentRegistered = 'payment_registered';
    case PaymentPaid = 'payment_paid';
    case PaymentCancelled = 'payment_cancelled';
    case PaymentReconciled = 'payment_reconciled';
    case RefundRequested = 'refund_requested';
    case RefundApproved = 'refund_approved';
    case RefundRejected = 'refund_rejected';
    case RefundPaid = 'refund_paid';
    case ComplimentaryApproved = 'complimentary_approved';
    case Issued = 'issued';
    case DocumentDownloaded = 'document_downloaded';
    case StatusChanged = 'status_changed';
    case ValidityChanged = 'validity_changed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case AdminCorrection = 'admin_correction';
    case FulfillmentChanged = 'fulfillment_changed';
    case Reserved = 'reserved';
    case ReservationChanged = 'reservation_changed';
    case ReservationReleased = 'reservation_released';
    case ReservationReviewed = 'reservation_reviewed';
    case ExpiryReminded = 'expiry_reminded';

    public function getLabel(): string
    {
        return match ($this) {
            self::Created => __('Создан черновик'),
            self::Updated => __('Изменены данные'),
            self::RecipientChanged => __('Указан получатель'),
            self::ItemAdded => __('Добавлена услуга'),
            self::ItemChanged => __('Изменена услуга'),
            self::ItemRemoved => __('Удалена услуга'),
            self::PackageApplied => __('Применён пакет'),
            self::Repriced => __('Пересчитаны цены'),
            self::PaymentRegistered => __('Выставлен счёт'),
            self::PaymentPaid => __('Оплата получена'),
            self::PaymentCancelled => __('Счёт отменён'),
            self::PaymentReconciled => __('Оплата сверена Финансами'),
            self::RefundRequested => __('Запрошены возврат и аннулирование'),
            self::RefundApproved => __('Возврат согласован'),
            self::RefundRejected => __('Возврат отклонён'),
            self::RefundPaid => __('Деньги возвращены'),
            self::ComplimentaryApproved => __('Бесплатный согласован директором'),
            self::Issued => __('Сертификат выпущен'),
            self::DocumentDownloaded => __('Скачан PDF сертификата'),
            self::StatusChanged => __('Изменён статус'),
            self::ValidityChanged => __('Изменён срок действия'),
            self::Cancelled => __('Сертификат аннулирован'),
            self::Expired => __('Срок действия истёк'),
            self::AdminCorrection => __('Исправление администратора'),
            self::FulfillmentChanged => __('Исполнение услуги'),
            self::Reserved => __('Отмечена бронь'),
            self::ReservationChanged => __('Изменена бронь'),
            self::ReservationReleased => __('Бронь снята'),
            self::ReservationReviewed => __('Проверка брони Финансами'),
            self::ExpiryReminded => __('Напоминание о сроке отправлено гостю'),
        };
    }
}
