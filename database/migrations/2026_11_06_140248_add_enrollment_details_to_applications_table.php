<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of the old enrolling screen's account and personal information, carried on a member transaction
 * until it is approved and copied to the member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('cif_key', 30)->nullable()->after('account_no');
            $table->string('segment', 1)->nullable()->after('category');
            $table->string('sex', 10)->nullable()->after('birthdate');
            $table->string('civil_status', 20)->nullable()->after('sex');
            $table->string('occupation')->nullable()->after('contact_number');
            $table->text('address')->nullable()->after('occupation');
            $table->text('office_address')->nullable()->after('address');
            $table->string('employer')->nullable()->after('office_address');
            $table->string('religion', 100)->nullable()->after('employer');
            $table->unsignedTinyInteger('dependents')->nullable()->after('religion');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['cif_key', 'segment', 'sex', 'civil_status', 'occupation', 'address', 'office_address', 'employer', 'religion', 'dependents']);
        });
    }
};
