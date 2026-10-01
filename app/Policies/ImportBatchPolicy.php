<?php

namespace App\Policies;

class ImportBatchPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'imports.view';

    protected string $createPermission = 'members.import';

    protected string $updatePermission = 'members.import';

    protected string $deletePermission = 'members.import';
}
