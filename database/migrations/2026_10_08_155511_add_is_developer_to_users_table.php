<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Developer accounts see the developer-only pages (System Usage). The cooperative's own Administrator does not.
 * The accounts already left out of the usage report are the developer's, so they start as developer accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_developer')->default(false)->after('in_usage_report');
        });

        DB::table('users')->where('in_usage_report', false)->update(['is_developer' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_developer');
        });
    }
};
