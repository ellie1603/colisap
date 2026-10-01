<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->renameColumn('claim_amount', 'gross_benefit');
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->renameColumn('paid_at', 'settled_at');
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->string('status', 20)->default('submitted')->change();
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->string('category', 10)->nullable()->after('beneficiary_id');
            $table->decimal('outstanding_loan', 12, 2)->default(0)->after('gross_benefit');
            $table->decimal('other_obligations', 12, 2)->default(0)->after('outstanding_loan');
            $table->decimal('net_benefit', 12, 2)->nullable()->after('other_obligations');
            $table->boolean('death_certificate_verified')->default(false)->after('net_benefit');
            $table->boolean('certificate_verified')->default(false)->after('death_certificate_verified')->comment('Duplicate approved COLISAP application or COLISAP certificate');
            $table->boolean('eligibility_verified')->default(false)->after('certificate_verified');
            $table->text('eligibility_notes')->nullable()->after('eligibility_verified');
            $table->foreignId('settled_by')->nullable()->after('settled_at')->constrained('users')->nullOnDelete();
        });

        DB::table('claims')->where('status', 'paid')->update(['status' => 'settled']);

        foreach (DB::table('claims')->get(['id', 'member_id', 'gross_benefit', 'approved_amount']) as $claim) {
            DB::table('claims')->where('id', $claim->id)->update([
                'category' => DB::table('members')->where('id', $claim->member_id)->value('category'),
                'gross_benefit' => $claim->approved_amount ?? $claim->gross_benefit,
                'net_benefit' => $claim->approved_amount ?? $claim->gross_benefit,
            ]);
        }

        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn('approved_amount');
        });

        Schema::create('claim_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained('claims')->cascadeOnDelete();
            $table->string('type', 20)->comment('loan, obligation');
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        Schema::create('claim_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained('claims')->cascadeOnDelete();
            $table->foreignId('beneficiary_id')->nullable()->constrained('beneficiaries')->nullOnDelete();
            $table->string('beneficiary_name');
            $table->decimal('share_percentage', 5, 2);
            $table->decimal('amount', 12, 2);
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference_no')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_payouts');
        Schema::dropIfExists('claim_deductions');

        Schema::table('claims', function (Blueprint $table) {
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->dropConstrainedForeignId('settled_by');
            $table->dropColumn(['category', 'outstanding_loan', 'other_obligations', 'net_benefit', 'death_certificate_verified', 'certificate_verified', 'eligibility_verified', 'eligibility_notes']);
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->renameColumn('settled_at', 'paid_at');
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->renameColumn('gross_benefit', 'claim_amount');
        });
    }
};
