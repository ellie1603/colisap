<?php

namespace App\Services\Policy;

use App\Models\PolicySetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Single source of truth for configurable COLISAP policy values.
 * Values live in `policy_settings` (editable by the Administrator) and fall back to PolicyDefaults.
 */
class PolicySettings
{
    private const CACHE_KEY = 'colisap.policy_settings';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $values = null;

    public function get(string $key): mixed
    {
        if (! array_key_exists($key, PolicyDefaults::SETTINGS)) {
            throw new InvalidArgumentException("Unknown COLISAP policy setting [{$key}].");
        }

        return $this->cast($key, $this->all()[$key] ?? PolicyDefaults::SETTINGS[$key]['value']);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function decimal(string $key): float
    {
        return (float) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values ??= Cache::rememberForever(self::CACHE_KEY, function () {
            $stored = Schema::hasTable('policy_settings')
                ? PolicySetting::query()->pluck('value', 'key')->all()
                : [];

            $values = [];

            foreach (PolicyDefaults::SETTINGS as $key => $setting) {
                $values[$key] = $this->cast($key, $stored[$key] ?? $setting['value']);
            }

            return $values;
        });
    }

    /**
     * Persist changed values (each change is audit-logged through the PolicySetting model).
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, PolicyDefaults::SETTINGS)) {
                continue;
            }

            $value = $this->cast($key, $value);
            $setting = PolicySetting::firstOrNew(['key' => $key]);

            if ($setting->exists && $this->cast($key, $setting->value) === $value) {
                continue;
            }

            $setting->fill(['value' => $value, 'updated_by' => Auth::id()])->save();
        }

        $this->flush();
    }

    public function flush(): void
    {
        $this->values = null;
        Cache::forget(self::CACHE_KEY);
    }

    public function benefitFor(?string $category): float
    {
        return $category === '60000' ? $this->decimal('benefit_60k') : $this->decimal('benefit_40k');
    }

    public function minimumBalanceFor(?string $category): float
    {
        return $category === '60000' ? $this->decimal('min_balance_60k') : $this->decimal('min_balance_40k');
    }

    private function cast(string $key, mixed $value): mixed
    {
        return match (PolicyDefaults::SETTINGS[$key]['type']) {
            'int' => (int) $value,
            'decimal' => round((float) $value, 2),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $value,
        };
    }
}
