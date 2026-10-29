<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BMPC member list uploads are kept in Import History too, and each BMPC member remembers the upload that added
 * it, so deleting that upload removes the members it brought in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->string('type', 20)->default('members')->after('id')->comment('members (COLISAP masterlist), bmpc_members (BMPC member list)');
        });

        Schema::table('bmpc_members', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->after('savings_balance')->constrained('import_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bmpc_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
