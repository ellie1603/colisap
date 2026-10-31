<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ApplicationPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'applications.view';

    protected string $createPermission = 'applications.create';

    protected string $updatePermission = 'applications.manage';

    protected string $deletePermission = 'applications.manage';

    /**
     * A Branch Manager opens their branch's re-applications to approve or reject them.
     */
    public function update(User $user, Model $model): bool
    {
        return parent::update($user, $model)
            || ($model instanceof Application && $model->canBeDecidedBy($user));
    }
}
