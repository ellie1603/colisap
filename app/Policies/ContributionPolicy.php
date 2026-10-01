<?php

namespace App\Policies;

class ContributionPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'contributions.view';

    protected string $createPermission = 'contributions.manage';

    protected string $updatePermission = 'contributions.manage';

    protected string $deletePermission = 'contributions.manage';
}
