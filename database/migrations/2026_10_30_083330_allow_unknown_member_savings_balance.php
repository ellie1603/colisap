<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A member's savings balance may be unknown (blank): a list imported without savings balances leaves it empty
 * instead of ₱0, so those members are not sent replenishment notices, or terminated, for a balance nobody gave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->decimal('savings_balance', 14, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::table('members')->whereNull('savings_balance')->update(['savings_balance' => 0]);

        Schema::table('members', function (Blueprint $table) {
            $table->decimal('savings_balance', 14, 2)->default(0)->change();
        });
    }
};
