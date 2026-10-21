<?php

use App\Services\Access\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * BMPC ONE Monitoring API management: the API keys (only their hashes) and a log of every request made with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key_hash', 64)->unique();
            $table->string('key_prefix', 20);
            $table->string('key_last_four', 4);
            $table->json('scopes');
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('monitoring_api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitoring_api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->string('endpoint', 100);
            $table->string('method', 10);
            $table->unsignedSmallInteger('http_status');
            $table->unsignedInteger('duration_ms');
            $table->string('source_ip', 45)->nullable();
            $table->timestamp('requested_at')->index();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate(Permissions::ADMIN, 'web')->givePermissionTo(Permission::findOrCreate('monitoring_api.manage', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_api_logs');
        Schema::dropIfExists('monitoring_api_keys');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', 'monitoring_api.manage')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
