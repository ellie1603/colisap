<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MemberPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'members.view';

    protected string $createPermission = 'members.create';

    protected string $updatePermission = 'members.update';

    protected string $deletePermission = 'members.delete';

    public function restore(User $user, Model $model): bool
    {
        return $this->allowed($user, 'members.restore');
    }

    public function restoreAny(User $user): bool
    {
        return $this->allowed($user, 'members.restore');
    }
}
