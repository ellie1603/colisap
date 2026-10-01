<?php

namespace Database\Factories;

use App\Models\Contribution;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contribution>
 */
class ContributionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'amount' => 5,
            'member_share' => 5,
            'coop_share' => 0,
            'contribution_date' => now(),
            'status' => 'deducted',
        ];
    }
}
