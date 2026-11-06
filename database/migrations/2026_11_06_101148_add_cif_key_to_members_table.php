<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A COLISAP member's CIF Key, copied from the BMPC member with the same savings number and name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('cif_key', 30)->nullable()->after('account_no')->index();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex(['cif_key']);
            $table->dropColumn('cif_key');
        });
    }
};
