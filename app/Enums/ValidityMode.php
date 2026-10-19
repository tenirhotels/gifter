<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Как считать срок бесплатного сертификата при выпуске. */
enum ValidityMode: string implements HasLabel
{
    case Package = 'package';
    case Duration = 'duration';
    case UntilDate = 'until_date';

    public function getLabel(): string
    {
        return match ($this) {
            self::Package => __('По пакету'),
            self::Duration => __('Длительность с даты'),
            self::UntilDate => __('До конкретного дня'),
        };
    }
}
