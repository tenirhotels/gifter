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
            self::SuperAdmin => 'Администратор',
            self::Sales => 'Продажи',
            self::SalesManager => 'Менеджер продаж',
            self::SalesDirector => 'Директор по продажам',
            self::FrontOffice => 'Front Office / Бронирование',
            self::Finance => 'Финансы',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Полный доступ, пользователи и журнал аудита',
            self::Sales => 'Клиенты, черновики, счета, выпуск сертификатов',
            self::SalesManager => 'Всё из «Продаж» + корректировки, срок действия, бесплатные, возвраты; без себестоимости',
            self::SalesDirector => 'Каталог, поставщики, цены и себестоимость, пакеты; согласует бесплатные сертификаты',
            self::FrontOffice => 'Просмотр сертификатов, бронь, заезд, использование',
            self::Finance => 'Просмотр сертификатов и оплат; финансовые исключения — фаза 7',
        };
    }
}
