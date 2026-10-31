<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BMPC members carry the rest of the coop list's details, each left empty when the file has none: the last
 * transaction date, segmentation (D/G/S), COLISAP category (40000/60000) and the account remarks
 * (deactivated, deceased, dormant-bal, dormant-txn).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bmpc_members', function (Blueprint $table) {
            $table->date('last_transaction_date')->nullable()->after('savings_balance');
            $table->string('segment', 1)->nullable()->after('last_transaction_date')->comment('D (Diamond), G (Gold), S (Silver); null = NA');
            $table->string('category', 10)->nullable()->after('segment')->comment('40000 or 60000');
            $table->string('remarks', 20)->nullable()->after('category')->comment('deactivated, deceased, dormant-bal, dormant-txn');
        });
    }

    public function down(): void
    {
        Schema::table('bmpc_members', function (Blueprint $table) {
            $table->dropColumn(['last_transaction_date', 'segment', 'category', 'remarks']);
        });
    }
};
