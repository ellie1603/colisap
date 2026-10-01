<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    /**
     * Define the model's default state: an effective (active) 40K participant with a healthy balance.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lastName = fake()->lastName();
        $firstName = fake()->firstName();

        return [
            'account_no' => '00101'.fake()->unique()->numerify('######'),
            'account_name' => mb_strtoupper("{$lastName}, {$firstName}"),
            'branch_id' => fn () => Branch::query()->inRandomOrder()->value('id'),
            'first_name' => $firstName,
            'middle_name' => fake()->lastName(),
            'last_name' => $lastName,
            'birthdate' => fake()->dateTimeBetween('-58 years', '-20 years'),
            'sex' => fake()->randomElement(['male', 'female']),
            'contact_number' => fake()->numerify('09#########'),
            'address' => fake()->address(),
            'application_date' => now()->subDays(225),
            'approval_date' => now()->subDays(210),
            'status' => 'active',
            'category' => '40000',
            'segment' => fake()->randomElement(['D', 'G', 'S', 'R']),
            'activated_at' => now()->subDays(30),
            'savings_balance' => 5000,
        ];
    }

    /**
     * Still inside the 180-day waiting period.
     */
    public function waiting(): static
    {
        return $this->state(fn (array $attributes): array => [
            'approval_date' => now()->subDays(30),
            'status' => 'waiting',
            'activated_at' => null,
        ]);
    }

    public function dormant(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'dormant',
            'savings_account_status' => 'dormant-txn',
            'dormant_since' => now()->subDays(20),
        ]);
    }

    public function deceased(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'deceased',
            'date_deceased' => now()->subDays(5),
        ]);
    }

    public function terminated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'terminated',
            'terminated_at' => now()->subDays(5),
            'termination_reason' => 'Test termination',
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'withdrawn',
            'withdrawn_at' => now()->subDays(5),
        ]);
    }

    public function category60000(): static
    {
        return $this->state(fn (array $attributes): array => [
            'category' => '60000',
        ]);
    }

    public function segment(?string $segment): static
    {
        return $this->state(fn (array $attributes): array => [
            'segment' => $segment,
        ]);
    }
}
