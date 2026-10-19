<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BatchStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Issued = 'issued';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Черновик'),
            self::Approved => __('Согласован'),
            self::Issued => __('Выпущен'),
            self::Cancelled => __('Аннулирован'),
        };
    }

    /** Подпись с учётом типа: бесплатный черновик ждёт директора, платный — оплаты. */
    public function labelFor(CertificateKind $kind): string
    {
        return match (true) {
            $this === self::Draft && $kind === CertificateKind::Complimentary => __('Ждёт согласования директора'),
            $this === self::Draft => __('Черновик: счёт и оплата'),
            default => $this->getLabel(),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Approved => 'info',
            self::Issued => 'success',
            self::Cancelled => 'danger',
        };
    }
}
