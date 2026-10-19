<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;

/** Плитки «Мои продажи» над таблицей страницы — для любого, кто имеет право видеть свои продажи (например, администратора-продавца). */
class MySalesWidget extends MyCommissionWidget
{
    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can(Permission::ViewOwnCommissions);
    }
}
