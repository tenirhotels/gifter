<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Certificate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

    /** Продавец видит только сертификаты, созданные им самим. */
    public function view(User $user, Model $model): bool
    {
        /** @var Certificate $model */
        return $user->can(Permission::ViewCertificates)
            && ($user->can(Permission::ViewAllCertificates) || $model->created_by === $user->id);
    }

    /** Реквизиты правятся только в своём черновике. */
    public function update(User $user, Model $model): bool
    {
        /** @var Certificate $model */
        return $model->isDraft() && $user->can(Permission::EditDrafts) && $this->view($user, $model);
    }

    /** Ограничение списков и поиска тем же правилом, что и view(). */
    public static function scopeVisible(Builder $query, ?User $user): Builder
    {
        if ($user?->can(Permission::ViewAllCertificates)) {
            return $query;
        }

        return $query->where($query->qualifyColumn('created_by'), $user?->id ?? 0);
    }
}
