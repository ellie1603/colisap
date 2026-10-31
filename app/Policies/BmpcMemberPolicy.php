<?php

namespace App\Policies;

/**
 * The Barbaza MPC member list is filled by importing the coop's Excel list; whoever may import may also add a
 * member one by one and correct a member's details by hand.
 */
class BmpcMemberPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'bmpc_members.view';

    protected string $createPermission = 'bmpc_members.import';

    protected string $updatePermission = 'bmpc_members.import';

    protected string $deletePermission = 'bmpc_members.import';
}
