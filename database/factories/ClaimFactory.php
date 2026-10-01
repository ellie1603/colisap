<?php

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\Claim;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Claim>
 */
class ClaimFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'beneficiary_id' => fn (array $attributes) => Beneficiary::factory()->create(['member_id' => $attributes['member_id']])->id,
            'date_of_death' => now()->subDays(10),
            'date_filed' => now(),
            'status' => 'submitted',
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'death_certificate_verified' => true,
            'certificate_verified' => true,
        ]);
    }
}
