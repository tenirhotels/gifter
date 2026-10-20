<?php

namespace App\Policies;

use App\Enums\Permission;

/** Заявки с сайта видят и обрабатывают продавцы; менять запись напрямую нельзя — только действия OnlineOrderManager. */
class OnlineOrderPolicy extends PermissionPolicy
{
    protected function viewPermission(): Permission
    {
        return Permission::ProcessOnlineOrders;
    }

    protected function managePermission(): ?Permission
    {
        return null;
    }
}
