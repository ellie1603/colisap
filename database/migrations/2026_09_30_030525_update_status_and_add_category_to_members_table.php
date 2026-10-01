<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->enum('status', ['waiting', 'active', 'dormant', 'deceased', 'withdrawn'])
                ->default('waiting')
                ->change();
        });

        Schema::table('members', function (Blueprint $table) {
            $table->enum('category', ['40000', '60000'])->default('40000')->after('status');
            $table->date('activated_at')->nullable()->after('category');
            $table->date('category_upgrade_requested_at')->nullable()->after('activated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['category', 'activated_at', 'category_upgrade_requested_at']);
        });

        Schema::table('members', function (Blueprint $table) {
            $table->enum('status', ['active', 'inactive', 'deceased', 'withdrawn'])
                ->default('active')
                ->change();
        });
    }
};
