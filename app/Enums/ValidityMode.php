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
            self::Package => 'По пакету',
            self::Duration => 'Длительность с даты',
            self::UntilDate => 'До конкретного дня',
        };
    }
}
