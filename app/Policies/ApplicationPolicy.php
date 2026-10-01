<?php

namespace App\Policies;

class ApplicationPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'applications.view';

    protected string $createPermission = 'applications.manage';

    protected string $updatePermission = 'applications.manage';

    protected string $deletePermission = 'applications.manage';
}
