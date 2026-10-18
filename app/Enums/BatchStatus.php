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
            self::Draft => 'Черновик',
            self::Approved => 'Согласован',
            self::Issued => 'Выпущен',
            self::Cancelled => 'Аннулирован',
        };
    }

    /** Подпись с учётом типа: бесплатный черновик ждёт директора, платный — оплаты. */
    public function labelFor(CertificateKind $kind): string
    {
        return match (true) {
            $this === self::Draft && $kind === CertificateKind::Complimentary => 'Ждёт согласования директора',
            $this === self::Draft => 'Черновик: счёт и оплата',
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
