<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member can ask not to receive SMS reminders; they are then skipped by every SMS sent from Monitoring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('sms_opt_out')->default(false)->after('contact_number');
            $table->date('sms_opt_out_at')->nullable()->after('sms_opt_out');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['sms_opt_out', 'sms_opt_out_at']);
        });
    }
};
