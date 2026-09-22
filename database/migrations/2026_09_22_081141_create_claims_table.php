<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->string('claim_no')->unique();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('beneficiary_id')->nullable()->constrained('beneficiaries')->nullOnDelete();
            $table->date('date_of_death');
            $table->date('date_filed');
            $table->enum('status', ['submitted', 'under_review', 'approved', 'paid', 'rejected'])->default('submitted');
            $table->decimal('claim_amount', 12, 2)->nullable();
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
