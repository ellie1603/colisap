<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps standard policy abilities onto RBAC permission names. Subclasses declare which permission
 * grants viewing and which grants managing; anything else (delete/restore) can be overridden.
 */
abstract class PermissionPolicy
{
    protected string $viewPermission;

    protected string $createPermission;

    protected string $updatePermission;

    protected string $deletePermission;

    protected function allowed(User $user, string $permission): bool
    {
        return $user->is_active && $user->can($permission);
    }

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, $this->viewPermission);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allowed($user, $this->viewPermission);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, $this->createPermission);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allowed($user, $this->updatePermission);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->allowed($user, $this->deletePermission);
    }

    public function deleteAny(User $user): bool
    {
        return $this->allowed($user, $this->deletePermission);
    }

    public function restore(User $user, Model $model): bool
    {
        return $this->allowed($user, $this->deletePermission);
    }

    public function restoreAny(User $user): bool
    {
        return $this->allowed($user, $this->deletePermission);
    }

    /**
     * COLISAP records are never permanently deleted through the application.
     */
    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
