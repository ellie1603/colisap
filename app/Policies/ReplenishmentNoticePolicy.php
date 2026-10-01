<?php

namespace App\Policies;

class ReplenishmentNoticePolicy extends PermissionPolicy
{
    protected string $viewPermission = 'monitoring.view';

    protected string $createPermission = 'monitoring.manage';

    protected string $updatePermission = 'monitoring.manage';

    protected string $deletePermission = 'monitoring.manage';
}
