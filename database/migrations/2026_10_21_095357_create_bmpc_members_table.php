<?php

use App\Services\Access\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every member of Barbaza MPC (not only COLISAP participants), imported from the coop's Excel list.
 * A new or active COLISAP transaction is filled in from here; nothing in this table counts as COLISAP data.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const PERMISSIONS = ['bmpc_members.view', 'bmpc_members.import'];

    public function up(): void
    {
        Schema::create('bmpc_members', function (Blueprint $table) {
            $table->id();
            $table->string('account_no', 30)->unique();
            $table->string('account_name')->nullable();
            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('suffix', 20)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('birthdate')->nullable();
            $table->string('sex', 10)->nullable();
            $table->string('contact_number', 50)->nullable();
            $table->string('address')->nullable();
            $table->decimal('savings_balance', 14, 2)->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['last_name', 'first_name']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate(Permissions::ADMIN, 'web')->givePermissionTo(self::PERMISSIONS);
        Role::findOrCreate(Permissions::CRS, 'web')->givePermissionTo(self::PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('bmpc_members');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
