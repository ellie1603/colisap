<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('type', 20)->default('new')->comment('new, reapplication');
            $table->string('category', 10)->default('40000');
            $table->date('application_date');
            $table->string('status', 20)->default('pending')->comment('pending, approved, rejected');
            $table->date('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->boolean('good_health_declared')->default(false);
            $table->boolean('age_verified')->default(false);
            $table->boolean('balance_verified')->default(false);
            $table->decimal('fee_amount', 10, 2)->default(0);
            $table->boolean('fee_paid')->default(false);
            $table->string('previous_status', 20)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'application_date']);
        });

        // Existing members were approved before this module existed; record that history.
        $now = now();

        foreach (DB::table('members')->whereNotNull('approval_date')->get(['id', 'category', 'approval_date', 'application_date']) as $member) {
            DB::table('applications')->insert([
                'member_id' => $member->id,
                'type' => 'new',
                'category' => $member->category ?? '40000',
                'application_date' => $member->application_date ?? $member->approval_date,
                'status' => 'approved',
                'approved_at' => $member->approval_date,
                'remarks' => 'Recorded from existing member data.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
