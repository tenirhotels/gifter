<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/** Цели бесплатных сертификатов (формулировки владельца, 24.10.2026). */
enum ComplimentaryPurpose: string implements HasDescription, HasLabel
{
    case Influencers = 'influencers';
    case Partners = 'partners';
    case Barter = 'barter';
    case Loyalty = 'loyalty';
    case Compensation = 'compensation';
    case Internal = 'internal';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Influencers => 'Инфлюенсеры',
            self::Partners => 'Партнёры',
            self::Barter => 'Бартер',
            self::Loyalty => 'Лояльность',
            self::Compensation => 'Компенсация',
            self::Internal => 'Внутренние',
            self::Other => 'Другое',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Influencers => 'Блогеры, лидеры мнений и контент-креаторы.',
            self::Partners => 'Агентства, компании и другие деловые партнёры.',
            self::Barter => 'Обмен на товары, услуги, рекламу или иные встречные обязательства без денежной оплаты.',
            self::Loyalty => 'Бонусы для постоянных гостей и участников программы лояльности.',
            self::Compensation => 'Компенсация гостям за претензии, неудобства или сервисные ситуации.',
            self::Internal => 'Служебное использование сотрудниками или внутренние мероприятия отеля.',
            self::Other => 'Случаи, не подходящие под остальные цели, — обязательно укажите причину.',
        };
    }
}
