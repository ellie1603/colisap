<?php

namespace Tests\Concerns;

use App\Models\User;

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
}
