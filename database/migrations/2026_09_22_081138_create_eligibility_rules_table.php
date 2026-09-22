<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eligibility_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('min_membership_months')->default(0);
            $table->decimal('min_contribution_balance', 12, 2)->default(0);
            $table->unsignedInteger('max_missed_contributions')->nullable();
            $table->decimal('benefit_amount', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eligibility_rules');
    }
};
