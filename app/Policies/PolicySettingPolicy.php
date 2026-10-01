<?php

namespace App\Policies;

class PolicySettingPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'policy.manage';

    protected string $createPermission = 'policy.manage';

    protected string $updatePermission = 'policy.manage';

    protected string $deletePermission = '__none';
}
