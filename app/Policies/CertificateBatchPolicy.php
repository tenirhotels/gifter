<?php

namespace App\Policies;

use App\Enums\Permission;

class CertificateBatchPolicy extends PermissionPolicy
{
    protected function viewPermission(): Permission
    {
        return Permission::ViewBatches;
    }

    protected function managePermission(): ?Permission
    {
        return Permission::CreateComplimentary;
    }
}
