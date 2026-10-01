<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('field', 40)->comment('status, category, segment, branch');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->string('reason')->nullable();
            $table->string('source', 20)->default('manual')->comment('manual, system, import');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->index(['member_id', 'field']);
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_histories');
    }
};
