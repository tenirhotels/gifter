<?php

namespace App\Policies;

use App\Enums\Permission;

class CustomerPolicy extends PermissionPolicy
{
    protected function viewPermission(): Permission
    {
        return Permission::ViewCustomers;
    }

    protected function managePermission(): ?Permission
    {
        return Permission::ManageCustomers;
    }
}
