<?php

namespace App\Policies;

class BeneficiaryPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'beneficiaries.view';

    protected string $createPermission = 'beneficiaries.manage';

    protected string $updatePermission = 'beneficiaries.manage';

    protected string $deletePermission = 'beneficiaries.manage';
}
