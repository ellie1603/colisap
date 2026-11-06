<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal information from the old enrolling screen that members did not have yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('civil_status', 20)->nullable()->after('sex');
            $table->string('occupation')->nullable()->after('address');
            $table->text('office_address')->nullable()->after('occupation');
            $table->string('employer')->nullable()->after('office_address');
            $table->string('religion', 100)->nullable()->after('employer');
            $table->unsignedTinyInteger('dependents')->nullable()->after('religion');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['civil_status', 'occupation', 'office_address', 'employer', 'religion', 'dependents']);
        });
    }
};
