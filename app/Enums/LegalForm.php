<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Организационно-правовая форма внешнего поставщика. */
enum LegalForm: string implements HasLabel
{
    case Ip = 'ip';
    case Too = 'too';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ip => 'ИП',
            self::Too => 'ТОО',
            self::Other => 'Другая организация',
        };
    }

    /** ИП — ИИН, организации — БИН. Формат и контрольный разряд у них одинаковые. */
    public function taxIdLabel(): string
    {
        return $this === self::Ip ? 'ИИН' : 'БИН';
    }
}
