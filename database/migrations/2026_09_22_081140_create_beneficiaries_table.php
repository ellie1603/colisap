<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('full_name');
            $table->string('relationship');
            $table->date('birthdate')->nullable();
            $table->string('contact_number')->nullable();
            $table->text('address')->nullable();
            $table->decimal('share_percentage', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['member_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficiaries');
    }
};
