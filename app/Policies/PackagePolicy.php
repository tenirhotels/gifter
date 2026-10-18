<?php

namespace App\Policies;

use App\Enums\Permission;

class PackagePolicy extends PermissionPolicy
{
    protected function viewPermission(): Permission
    {
        return Permission::ViewCatalog;
    }

    protected function managePermission(): ?Permission
    {
        return Permission::ManageCatalog;
    }
}
