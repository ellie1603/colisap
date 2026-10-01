<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority')->default(1)->after('share_percentage');
            $table->string('id_type')->nullable()->after('priority');
            $table->string('id_number')->nullable()->after('id_type');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn(['priority', 'id_type', 'id_number']);
        });
    }
};
