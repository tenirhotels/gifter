<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CertificateKind: string implements HasColor, HasLabel
{
    case Sale = 'sale';
    /** Бесплатный: выпускается без оплаты, стоимость по прайсу — маркетинговый расход. */
    case Complimentary = 'complimentary';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sale => 'Продажа',
            self::Complimentary => 'Бесплатный (маркетинг)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sale => 'gray',
            self::Complimentary => 'info',
        };
    }
}
