<?php

use App\Services\Access\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Branch staff file applications (the Administrator still decides them), and an Excel import from a branch
 * waits for the Administrator's approval. Other permissions stay as the Administrator set them.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>> permission => roles that receive it
     */
    private const GRANTS = [
        'applications.create' => [Permissions::ADMIN, Permissions::CRS],
        'imports.approve' => [Permissions::ADMIN],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::GRANTS as $name => $roles) {
            $permission = Permission::findOrCreate($name, 'web');

            foreach ($roles as $role) {
                Role::findOrCreate($role, 'web')->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::whereIn('name', array_keys(self::GRANTS))->where('guard_name', 'web')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
