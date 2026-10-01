<?php

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Beneficiary>
 */
class BeneficiaryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'full_name' => fake()->name(),
            'relationship' => fake()->randomElement(['Spouse', 'Child', 'Parent', 'Sibling']),
            'contact_number' => fake()->phoneNumber(),
            'share_percentage' => 100,
            'is_active' => true,
        ];
    }
}
