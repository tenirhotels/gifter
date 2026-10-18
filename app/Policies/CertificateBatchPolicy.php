<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CertificateBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Покупки видны как сертификаты: продавцу — свои, остальным ролям с правом просмотра — все. */
class CertificateBatchPolicy extends PermissionPolicy
{
    protected function viewPermission(): Permission
    {
        return Permission::ViewCertificates;
    }

    /** Создаются только мастером «Новый сертификат». */
    protected function managePermission(): ?Permission
    {
        return null;
    }

    public function view(User $user, Model $model): bool
    {
        /** @var CertificateBatch $model */
        return $user->can(Permission::ViewCertificates)
            && ($user->can(Permission::ViewAllCertificates) || $model->created_by === $user->id);
    }

    public static function scopeVisible(Builder $query, ?User $user): Builder
    {
        if ($user?->can(Permission::ViewAllCertificates)) {
            return $query;
        }

        return $query->where($query->qualifyColumn('created_by'), $user?->id ?? 0);
    }
}
