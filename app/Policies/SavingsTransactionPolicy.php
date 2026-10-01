<?php

namespace App\Policies;

class SavingsTransactionPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'savings.view';

    protected string $createPermission = 'savings.manage';

    protected string $updatePermission = 'savings.manage';

    protected string $deletePermission = 'savings.manage';
}
