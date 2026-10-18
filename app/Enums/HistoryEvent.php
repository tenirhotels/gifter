<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum HistoryEvent: string implements HasLabel
{
    case Created = 'created';
    case Updated = 'updated';
    case RecipientChanged = 'recipient_changed';
    case ItemAdded = 'item_added';
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

    public function getLabel(): string
    {
        return match ($this) {
            self::Created => 'Создан черновик',
            self::Updated => 'Изменены данные',
            self::RecipientChanged => 'Указан получатель',
            self::ItemAdded => 'Добавлена услуга',
            self::ItemRemoved => 'Удалена услуга',
            self::PackageApplied => 'Применён пакет',
            self::Repriced => 'Пересчитаны цены',
            self::PaymentRegistered => 'Выставлен счёт',
            self::PaymentPaid => 'Оплата получена',
            self::PaymentCancelled => 'Счёт отменён',
            self::PaymentReconciled => 'Оплата сверена Финансами',
            self::RefundRequested => 'Запрошены возврат и аннулирование',
            self::RefundApproved => 'Возврат согласован',
            self::RefundRejected => 'Возврат отклонён',
            self::RefundPaid => 'Деньги возвращены',
            self::ComplimentaryApproved => 'Бесплатный согласован директором',
            self::Issued => 'Сертификат выпущен',
            self::DocumentDownloaded => 'Скачан PDF сертификата',
            self::StatusChanged => 'Изменён статус',
            self::ValidityChanged => 'Изменён срок действия',
            self::Cancelled => 'Сертификат аннулирован',
            self::Expired => 'Срок действия истёк',
            self::AdminCorrection => 'Исправление администратора',
            self::FulfillmentChanged => 'Исполнение услуги',
        };
    }
}
