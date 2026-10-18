<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Политика «просмотр / управление» по матрице Permission.
 * Удаление запрещено для всех, включая администратора.
 */
abstract class PermissionPolicy
{
    abstract protected function viewPermission(): Permission;

    /** null — создание и изменение через панель недоступны никому. */
    abstract protected function managePermission(): ?Permission;

    public function viewAny(User $user): bool
    {
        return $user->can($this->viewPermission());
    }

    public function view(User $user, Model $model): bool
    {
        return $user->can($this->viewPermission());
    }

    public function create(User $user): bool
    {
        return $this->managePermission() !== null && $user->can($this->managePermission());
    }

    public function update(User $user, Model $model): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
