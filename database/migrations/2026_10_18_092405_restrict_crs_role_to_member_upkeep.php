<?php

use App\Services\Access\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The CRS Officer's part is only to add members (by filing applications), update them and file re-applications.
 * Importing, exporting, monitoring, reports, savings and import history stay with the Administrator.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const REVOKED = [
        'members.import', 'members.export', 'savings.view', 'monitoring.view', 'monitoring.send_sms', 'reports.view', 'imports.view',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate(Permissions::CRS, 'web')->revokePermissionTo(self::REVOKED);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate(Permissions::CRS, 'web')->givePermissionTo(self::REVOKED);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
