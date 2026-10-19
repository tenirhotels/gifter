<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum Role: string implements HasDescription, HasLabel
{
    case SuperAdmin = 'super_admin';
    case Sales = 'sales';
    case SalesManager = 'sales_manager';
    case SalesDirector = 'sales_director';
    case FrontOffice = 'front_office_reservations';
    case Finance = 'finance';

    public function getLabel(): string
    {
        return match ($this) {
            self::SuperAdmin => __('Администратор'),
            self::Sales => __('Продажи'),
            self::SalesManager => __('Менеджер продаж'),
            self::SalesDirector => __('Директор по продажам'),
            self::FrontOffice => __('Front Office / Бронирование'),
            self::Finance => __('Финансы'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::SuperAdmin => __('Полный доступ, пользователи и журнал аудита'),
            self::Sales => __('Клиенты, черновики, счета, выпуск сертификатов'),
            self::SalesManager => __('Всё из «Продаж» + корректировки, срок действия, бесплатные, возвраты; без себестоимости'),
            self::SalesDirector => __('Каталог, поставщики, цены и себестоимость, пакеты; согласует бесплатные сертификаты'),
            self::FrontOffice => __('Просмотр сертификатов, бронь, заезд, использование'),
            self::Finance => __('Просмотр сертификатов и оплат; финансовые исключения — фаза 7'),
        };
    }
}
