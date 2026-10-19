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
            self::Influencers => __('Инфлюенсеры'),
            self::Partners => __('Партнёры'),
            self::Barter => __('Бартер'),
            self::Loyalty => __('Лояльность'),
            self::Compensation => __('Компенсация'),
            self::Internal => __('Внутренние'),
            self::Other => __('Другое'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Influencers => __('Блогеры, лидеры мнений и контент-креаторы.'),
            self::Partners => __('Агентства, компании и другие деловые партнёры.'),
            self::Barter => __('Обмен на товары, услуги, рекламу или иные встречные обязательства без денежной оплаты.'),
            self::Loyalty => __('Бонусы для постоянных гостей и участников программы лояльности.'),
            self::Compensation => __('Компенсация гостям за претензии, неудобства или сервисные ситуации.'),
            self::Internal => __('Служебное использование сотрудниками или внутренние мероприятия отеля.'),
            self::Other => __('Случаи, не подходящие под остальные цели, — обязательно укажите причину.'),
        };
    }
}
