<?php

use App\Services\Access\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Two roles remain: Administrator (full access) and CRS Officer.
 * Super Admins become Administrators and Auditors become CRS Officers.
 * The mortuary claims/contributions module is retired: its permissions go, its tables and data stay.
 */
return new class extends Migration
{
    private const RETIRED_PERMISSIONS = [
        'claims.view', 'claims.manage', 'claims.approve', 'claims.settle',
        'contributions.view', 'contributions.manage',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(Permissions::ALL) as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $roleIds = [
            Permissions::ADMIN => Role::findOrCreate(Permissions::ADMIN, 'web')->id,
            Permissions::CRS => Role::findOrCreate(Permissions::CRS, 'web')->id,
        ];

        foreach (['super_admin' => Permissions::ADMIN, 'auditor' => Permissions::CRS] as $retiredRole => $newRole) {
            $retiredRoleId = DB::table('roles')->where('name', $retiredRole)->where('guard_name', 'web')->value('id');

            if ($retiredRoleId === null) {
                continue;
            }

            foreach (DB::table('model_has_roles')->where('role_id', $retiredRoleId)->get() as $assignment) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $roleIds[$newRole],
                    'model_type' => $assignment->model_type,
                    'model_id' => $assignment->model_id,
                ]);
            }

            DB::table('model_has_roles')->where('role_id', $retiredRoleId)->delete();
            DB::table('role_has_permissions')->where('role_id', $retiredRoleId)->delete();
            DB::table('roles')->where('id', $retiredRoleId)->delete();
        }

        Permission::whereIn('name', self::RETIRED_PERMISSIONS)->where('guard_name', 'web')->get()->each->delete();

        foreach (Permissions::defaults() as $role => $permissions) {
            Role::findByName($role, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::RETIRED_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('auditor', 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
