<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('file_name');
            $table->string('file_path')->nullable();
            $table->date('as_of_date');
            $table->string('status', 20)->default('uploaded')->comment('uploaded, analyzed, staged, importing, completed, failed, cancelled');
            $table->json('sheets')->nullable()->comment('Per-sheet detection, branch & column mapping');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('new_rows')->default(0);
            $table->unsignedInteger('update_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->text('error_message')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->string('sheet');
            $table->unsignedInteger('row_number');
            $table->string('account_no')->nullable();
            $table->string('account_name')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('action', 20)->comment('new, update, duplicate, invalid');
            $table->json('data')->nullable()->comment('Normalized values');
            $table->json('raw')->nullable()->comment('Original cell values');
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->string('status', 20)->default('pending')->comment('pending, imported, skipped');
            $table->string('result', 20)->nullable()->comment('created, updated, unchanged');
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamps();

            $table->index(['import_batch_id', 'status']);
            $table->index(['import_batch_id', 'action']);
            $table->index(['import_batch_id', 'account_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
