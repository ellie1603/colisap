<?php

namespace App\Policies;

/**
 * The Barbaza MPC member list is filled only by importing the coop's Excel list.
 */
class BmpcMemberPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'bmpc_members.view';

    protected string $createPermission = 'bmpc_members.import';

    protected string $updatePermission = 'bmpc_members.import';

    protected string $deletePermission = 'bmpc_members.import';
}
