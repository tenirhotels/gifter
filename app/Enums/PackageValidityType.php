<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Как задан срок пакета: на срок с даты продажи или на конкретный период. */
enum PackageValidityType: string implements HasLabel
{
    case Term = 'term';
    case Period = 'period';

    public function getLabel(): string
    {
        return match ($this) {
            self::Term => __('На срок с даты продажи'),
            self::Period => __('На конкретный период'),
        };
    }
}
