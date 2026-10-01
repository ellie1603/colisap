<?php

namespace App\Policies;

class BranchPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'branches.manage';

    protected string $createPermission = 'branches.manage';

    protected string $updatePermission = 'branches.manage';

    protected string $deletePermission = 'branches.manage';
}
