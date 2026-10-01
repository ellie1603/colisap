<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\ReplenishmentNotice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReplenishmentNotice>
 */
class ReplenishmentNoticeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'category' => '40000',
            'required_balance' => 500,
            'balance_at_notice' => 300,
            'shortfall' => 200,
            'notice_date' => now(),
            'deadline' => now()->addDays(15),
            'status' => 'open',
        ];
    }
}
