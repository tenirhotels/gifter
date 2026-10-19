<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Правила комиссии: видят те, кто видит комиссии всех; создаёт версии администратор. Править и удалять нельзя. */
class CommissionRulePolicy extends PermissionPolicy
{
    protected function viewPermission(): Permission
    {
        return Permission::ViewAllCommissions;
    }

    protected function managePermission(): ?Permission
    {
        return Permission::ManageCommissionRules;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }
}
