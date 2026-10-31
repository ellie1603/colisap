<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dormant in transaction means no transaction for 3 months, so the last transaction dates recorded in this system
 * (by the savings import and savings entries) count as activity too, not only the masterlist's dormancy flag.
 * Changed only where the setting was still the old default; an Administrator's own choice stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('policy_settings')
            ->where('key', 'dormancy_activity_source')
            ->where('value', json_encode('masterlist_flag'))
            ->update(['value' => json_encode('both'), 'updated_at' => now()]);

        Cache::forget('colisap.policy_settings');
    }

    public function down(): void
    {
        DB::table('policy_settings')
            ->where('key', 'dormancy_activity_source')
            ->where('value', json_encode('both'))
            ->update(['value' => json_encode('masterlist_flag'), 'updated_at' => now()]);

        Cache::forget('colisap.policy_settings');
    }
};
