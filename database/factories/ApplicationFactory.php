<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory()->state(['approval_date' => null, 'status' => 'waiting']),
            'type' => 'new',
            'category' => '40000',
            'application_date' => now()->subDays(3),
            'status' => 'pending',
            'good_health_declared' => true,
            'age_verified' => true,
            'balance_verified' => true,
        ];
    }

    public function reapplication(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'reapplication',
            'fee_amount' => 30,
            'fee_paid' => true,
        ]);
    }
}
