<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replenishment_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('category', 10);
            $table->decimal('required_balance', 12, 2);
            $table->decimal('balance_at_notice', 14, 2);
            $table->decimal('shortfall', 12, 2);
            $table->date('notice_date');
            $table->date('deadline');
            $table->string('status', 20)->default('open')->comment('open, replenished, terminated, downgraded, cancelled');
            $table->date('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'deadline']);
            $table->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replenishment_notices');
    }
};
