<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Certificate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CertificatePolicy extends PermissionPolicy
{
    protected function viewPermission(): Permission
    {
        return Permission::ViewCertificates;
    }

    protected function managePermission(): ?Permission
    {
        return Permission::CreateCertificates;
    }

    /** Реквизиты правятся только в черновике. */
    public function update(User $user, Model $model): bool
    {
        /** @var Certificate $model */
        return $model->isDraft() && $user->can(Permission::EditDrafts);
    }
}
