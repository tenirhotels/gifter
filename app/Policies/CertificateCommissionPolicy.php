<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Комиссия видна своему продавцу и тем, кто видит комиссии всех; менять напрямую нельзя — только действия CommissionManager. */
class CertificateCommissionPolicy extends PermissionPolicy
{
    protected function viewPermission(): Permission
    {
        return Permission::ViewAllCommissions;
    }

    protected function managePermission(): ?Permission
    {
        return null;
    }

    public function view(User $user, Model $model): bool
    {
        return $user->can(Permission::ViewAllCommissions) || ($user->can(Permission::ViewOwnCommissions) && $model->seller_id === $user->id);
    }
}
