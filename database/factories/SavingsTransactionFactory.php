<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\SavingsTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsTransaction>
 */
class SavingsTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'type' => 'deposit',
            'amount' => fake()->randomFloat(2, 100, 2000),
            'transaction_date' => now(),
            'reference_no' => 'OR-'.fake()->unique()->numerify('#####'),
        ];
    }

    public function withdrawal(float $amount): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'withdrawal',
            'amount' => $amount,
        ]);
    }
}
