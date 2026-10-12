<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An application now stands on its own: it carries the applicant's details and is not tied to a member
 * record until it is approved. Only then is the member created (or, for a re-application, updated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable()->change();

            $table->string('account_no', 30)->nullable()->after('member_id');
            $table->string('last_name')->nullable()->after('account_no');
            $table->string('first_name')->nullable()->after('last_name');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->foreignId('branch_id')->nullable()->after('middle_name')->constrained('branches')->nullOnDelete();
            $table->date('birthdate')->nullable()->after('branch_id');
            $table->string('contact_number', 50)->nullable()->after('birthdate');
            $table->decimal('savings_balance', 12, 2)->nullable()->after('contact_number');

            $table->index('account_no');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['account_no']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['account_no', 'last_name', 'first_name', 'middle_name', 'birthdate', 'contact_number', 'savings_balance']);
        });
    }
};
