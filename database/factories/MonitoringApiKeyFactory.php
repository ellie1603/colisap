<?php

namespace Database\Factories;

use App\Models\MonitoringApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Keys made by the factory have an unknown raw value; use MonitoringApiKey::generate() when a test needs to call the API.
 *
 * @extends Factory<MonitoringApiKey>
 */
class MonitoringApiKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $raw = MonitoringApiKey::PREFIX.Str::random(48);

        return [
            'name' => 'BMPC ONE',
            'key_hash' => MonitoringApiKey::hash($raw),
            'key_prefix' => substr($raw, 0, strlen(MonitoringApiKey::PREFIX) + 4),
            'key_last_four' => substr($raw, -4),
            'scopes' => array_keys(MonitoringApiKey::SCOPES),
            'status' => 'active',
        ];
    }

    public function revoked(): static
    {
        return $this->state(['status' => 'revoked', 'revoked_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }
}
