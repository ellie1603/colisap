<?php

use App\Services\Access\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRS Officers import and export the Excel masterlist again, and follow their uploads in Import History.
 * Monitoring, SMS, reports and savings stay with the Administrator.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const GRANTED = ['members.import', 'members.export', 'imports.view'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate(Permissions::CRS, 'web')->givePermissionTo(self::GRANTED);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate(Permissions::CRS, 'web')->revokePermissionTo(self::GRANTED);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
