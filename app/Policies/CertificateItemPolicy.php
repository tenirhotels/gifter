<?php

namespace App\Policies;

use App\Enums\Permission;

class CertificateItemPolicy extends PermissionPolicy
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
