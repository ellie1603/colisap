<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ClaimPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'claims.view';

    protected string $createPermission = 'claims.manage';

    protected string $updatePermission = 'claims.manage';

    protected string $deletePermission = 'claims.approve';

    /**
     * Approved, settled or rejected claims are part of the permanent record and cannot be edited.
     */
    public function update(User $user, Model $model): bool
    {
        return in_array($model->status, ['submitted', 'under_review'], true) && parent::update($user, $model);
    }

    /**
     * Only claims that were never processed may be archived.
     */
    public function delete(User $user, Model $model): bool
    {
        return $model->status === 'submitted' && parent::delete($user, $model);
    }
}
