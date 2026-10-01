<?php

use App\Services\Policy\PolicyDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $values = array_map(fn (array $setting) => $setting['value'], PolicyDefaults::SETTINGS);

        // Carry over amounts previously configured as eligibility rules.
        if (Schema::hasTable('eligibility_rules')) {
            foreach (DB::table('eligibility_rules')->where('is_active', true)->get() as $rule) {
                $suffix = $rule->category === '60000' ? '60k' : ($rule->category === '40000' ? '40k' : null);

                if ($suffix) {
                    $values["benefit_{$suffix}"] = (float) ($rule->benefit_amount ?? $values["benefit_{$suffix}"]);
                    $values["min_balance_{$suffix}"] = (float) $rule->min_contribution_balance;
                }
            }
        }

        $now = now();

        DB::table('policy_settings')->insert(array_map(fn (string $key) => [
            'key' => $key,
            'value' => json_encode($values[$key]),
            'created_at' => $now,
            'updated_at' => $now,
        ], array_keys($values)));
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_settings');
    }
};
