<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\Access\Permissions;
use Spatie\Permission\Models\Role;

trait ActsAsRole
{
    /**
     * Sign in as an active user with the given role (roles & permissions come from the migrations).
     */
    protected function actingAsRole(string $role, bool $isActive = true): User
    {
        $user = User::factory()->create(['is_active' => $isActive])->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    /**
     * Sign in as the developer (super admin) account, the only one that manages Staff Accounts.
     */
    protected function actingAsDeveloper(): User
    {
        $developer = User::factory()->create(['is_active' => true, 'is_developer' => true])->assignRole(Permissions::ADMIN);
        $this->actingAs($developer);

        return $developer;
    }

    /**
     * Give a role extra permissions, as an Administrator can on the Roles page.
     */
    protected function grantToRole(string $role, string ...$permissions): void
    {
        Role::findByName($role, 'web')->givePermissionTo($permissions);
    }
}
