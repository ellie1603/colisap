<?php

namespace Tests\Feature\Database;

use App\Models\User;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ConsolidateRolesMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_super_admins_become_administrators_and_auditors_become_crs_officers(): void
    {
        $superAdmin = User::factory()->create()->assignRole(Role::findOrCreate('super_admin', 'web'));
        $auditor = User::factory()->create()->assignRole(Role::findOrCreate('auditor', 'web'));
        $admin = User::factory()->create()->assignRole(Permissions::ADMIN);
        Permission::findOrCreate('claims.view', 'web');

        (require database_path('migrations/2026_10_04_085928_consolidate_roles_and_retire_mortuary_module.php'))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame([Permissions::ADMIN], $superAdmin->fresh()->getRoleNames()->all());
        $this->assertSame([Permissions::CRS], $auditor->fresh()->getRoleNames()->all());
        $this->assertSame([Permissions::ADMIN], $admin->fresh()->getRoleNames()->all());
        $this->assertSame([Permissions::ADMIN, Permissions::BRANCH_MANAGER, Permissions::CRS], Role::orderBy('name')->pluck('name')->all(), 'The Branch Manager role was added later and is kept.');
        $this->assertFalse(Permission::where('name', 'like', 'claims.%')->orWhere('name', 'like', 'contributions.%')->exists());
        $this->assertTrue($auditor->fresh()->can('members.update'));
        $this->assertFalse($auditor->fresh()->can('users.manage'));
    }
}
