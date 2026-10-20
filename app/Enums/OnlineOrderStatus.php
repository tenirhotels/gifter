<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Заявка с сайта: новая → оформлена (создан черновик сертификата) или отклонена. */
enum OnlineOrderStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Processed = 'processed';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => __('Новая'),
            self::Processed => __('Оформлена'),
            self::Rejected => __('Отклонена'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::Processed => 'success',
            self::Rejected => 'gray',
        };
    }
}
