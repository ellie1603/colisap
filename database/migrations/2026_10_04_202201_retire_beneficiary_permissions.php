<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The beneficiaries module is retired: its permissions go, the beneficiaries table and its data stay.
 */
return new class extends Migration
{
    private const RETIRED_PERMISSIONS = ['beneficiaries.view', 'beneficiaries.manage'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::whereIn('name', self::RETIRED_PERMISSIONS)->where('guard_name', 'web')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::RETIRED_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
