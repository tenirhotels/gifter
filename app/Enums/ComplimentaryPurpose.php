<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ComplimentaryPurpose: string implements HasLabel
{
    case Marketing = 'marketing';
    case Blogger = 'blogger';
    case Barter = 'barter';

    public function getLabel(): string
    {
        return match ($this) {
            self::Marketing => 'Маркетинг',
            self::Blogger => 'Блогеры',
            self::Barter => 'Бартер',
        };
    }
}
