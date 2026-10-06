<?php

use App\Services\Access\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Monitoring can text members: the new permission goes to both roles, other permissions stay as the Administrator set them.
 */
return new class extends Migration
{
    private const PERMISSION = 'monitoring.send_sms';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate(self::PERMISSION, 'web');

        foreach ([Permissions::ADMIN, Permissions::CRS] as $role) {
            Role::findOrCreate($role, 'web')->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::where('name', self::PERMISSION)->where('guard_name', 'web')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
