<?php

namespace App\Policies;

use App\Enums\Permission;

class AuditLogPolicy extends PermissionPolicy
{
    protected function viewPermission(): Permission
    {
        return Permission::ViewAuditLog;
    }

    protected function managePermission(): ?Permission
    {
        return null;
    }
}
