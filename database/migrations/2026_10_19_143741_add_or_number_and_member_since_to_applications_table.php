<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member requests now cover four kinds: new member, active member (already enrolled, joining this system),
 * upgrade to the higher category and re-application. A re-application records the official receipt (OR)
 * number of the fee; an active member records the date they were originally approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('or_number', 50)->nullable()->after('fee_paid');
            $table->date('member_since')->nullable()->after('application_date');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['or_number', 'member_since']);
        });
    }
};
