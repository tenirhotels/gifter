<?php

namespace App\Policies;

use App\Enums\Permission;

class RefundPolicy extends PermissionPolicy
{
    protected function viewPermission(): Permission
    {
        return Permission::ViewCertificates;
    }

    protected function managePermission(): ?Permission
    {
        return null;
    }
}
