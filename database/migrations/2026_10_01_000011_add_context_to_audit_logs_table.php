<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('module', 40)->nullable()->after('action');
            $table->string('reason')->nullable()->after('description');
            $table->string('user_agent')->nullable()->after('ip_address');

            $table->index(['module', 'created_at']);
            $table->index('created_at');
        });

        foreach (DB::table('audit_logs')->select('auditable_type')->distinct()->pluck('auditable_type') as $type) {
            DB::table('audit_logs')->where('auditable_type', $type)->update(['module' => class_basename($type)]);
        }
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['module', 'created_at']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['module', 'reason', 'user_agent']);
        });
    }
};
