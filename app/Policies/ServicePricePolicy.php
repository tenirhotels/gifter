<?php

namespace App\Policies;

use App\Enums\Permission;

class ServicePricePolicy extends PermissionPolicy
{
    protected function viewPermission(): Permission
    {
        return Permission::ViewCatalog;
    }

    protected function managePermission(): ?Permission
    {
        return Permission::ManagePrices;
    }
}
