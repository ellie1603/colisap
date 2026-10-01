<?php

use App\Services\Access\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(Permissions::ALL) as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // "staff" becomes CRS (keeps its users).
        DB::table('roles')->where('name', 'staff')->where('guard_name', 'web')->update(['name' => Permissions::CRS]);

        $superAdmin = Role::findOrCreate(Permissions::SUPER_ADMIN, 'web');

        foreach (Permissions::defaults() as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }

        // Existing administrators keep full control (user management was admin-only before).
        $adminRoleId = DB::table('roles')->where('name', Permissions::ADMIN)->value('id');

        foreach (DB::table('model_has_roles')->where('role_id', $adminRoleId)->get() as $assignment) {
            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $superAdmin->id,
                'model_type' => $assignment->model_type,
                'model_id' => $assignment->model_id,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Eligibility rules were moved into policy_settings (see 2026_10_01_000002).
        Schema::dropIfExists('eligibility_rules');
    }

    public function down(): void
    {
        Schema::create('eligibility_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('category', ['40000', '60000'])->nullable()->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('min_membership_months')->default(0);
            $table->decimal('min_contribution_balance', 12, 2)->default(0);
            $table->unsignedInteger('max_missed_contributions')->nullable();
            $table->decimal('benefit_amount', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('roles')->where('name', Permissions::CRS)->update(['name' => 'staff']);
        Role::where('name', Permissions::SUPER_ADMIN)->delete();
    }
};
