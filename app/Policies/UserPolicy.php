<?php

namespace App\Policies;

class UserPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'users.manage';

    protected string $createPermission = 'users.manage';

    protected string $updatePermission = 'users.manage';

    protected string $deletePermission = 'users.manage';
}
