<?php

namespace App\Policies;

use App\Enums\Permission;

class UserPolicy extends PermissionPolicy
{
    protected function viewPermission(): Permission
    {
        return Permission::ManageUsers;
    }

    protected function managePermission(): ?Permission
    {
        return Permission::ManageUsers;
    }
}
