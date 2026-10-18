<?php

namespace App\Policies;

use App\Enums\Permission;

/** Заявки видны тем, кто видит сертификаты; меняются только действиями FulfillmentManager (ManageFulfillments). */
class ServiceFulfillmentPolicy extends PermissionPolicy
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
