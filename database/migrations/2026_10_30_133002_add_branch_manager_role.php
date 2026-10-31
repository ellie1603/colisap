<?php

use App\Services\Access\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds the Branch Manager role: views the dashboard, members, BMPC members and monitoring of their branch, and
 * approves the re-applications CRS Officers send. CRS Officers may also import savings lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('applications.approve_reapplications', 'web');
        Role::findOrCreate(Permissions::BRANCH_MANAGER, 'web')->syncPermissions(Permissions::defaults()[Permissions::BRANCH_MANAGER]);
        Role::findByName(Permissions::ADMIN, 'web')->givePermissionTo('applications.approve_reapplications');
        // CRS Officers encode members' savings too: the savings import on Member Transactions.
        Role::findByName(Permissions::CRS, 'web')->givePermissionTo('savings.manage');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::where('name', Permissions::CRS)->where('guard_name', 'web')->first()?->revokePermissionTo('savings.manage');
        Role::where('name', Permissions::BRANCH_MANAGER)->where('guard_name', 'web')->first()?->delete();
        Permission::where('name', 'applications.approve_reapplications')->where('guard_name', 'web')->first()?->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
