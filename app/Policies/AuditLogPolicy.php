<?php

namespace App\Policies;

class AuditLogPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'audit.view';

    protected string $createPermission = '__none';

    protected string $updatePermission = '__none';

    protected string $deletePermission = '__none';
}
