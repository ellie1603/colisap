<?php

namespace Database\Factories;

use App\Models\BmpcMember;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BmpcMember>
 */
class BmpcMemberFactory extends Factory
{
    /**
     * A Barbaza MPC member from the coop's member list.
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
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => fake()->lastName(),
            'branch_id' => fn () => Branch::query()->inRandomOrder()->value('id'),
            'birthdate' => fake()->dateTimeBetween('-58 years', '-20 years'),
            'sex' => fake()->randomElement(['male', 'female']),
            'contact_number' => fake()->numerify('09#########'),
            'savings_balance' => 5000,
        ];
    }
}
