<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Branch extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'name',
        'code',
        'aliases',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'aliases' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * @var array<int, Collection<int, Branch>>
     */
    private static array $lookupCache = [];

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /**
     * Branch options for selects, in official order.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all();
    }

    /**
     * Normalize a free-text branch/sheet name ("BAROTAC VIEJO", "Guinsangan", "Pres. Roxas") to a master branch.
     */
    public static function resolve(?string $name): ?self
    {
        $key = self::normalize((string) $name);

        if ($key === '') {
            return null;
        }

        $branches = self::$lookupCache[0] ??= static::query()->orderBy('sort_order')->get();

        foreach ($branches as $branch) {
            $candidates = [$branch->name, $branch->code, ...($branch->aliases ?? [])];

            foreach ($candidates as $candidate) {
                if ($candidate !== null && self::normalize($candidate) === $key) {
                    return $branch;
                }
            }
        }

        $key = str_replace(['president', 'pres'], 'p', $key);

        foreach ($branches as $branch) {
            $branchKey = str_replace(['president', 'pres'], 'p', self::normalize($branch->name));

            if (levenshtein($key, $branchKey) <= max(1, intdiv(strlen($branchKey), 6))) {
                return $branch;
            }
        }

        return null;
    }

    public static function flushLookupCache(): void
    {
        self::$lookupCache = [];
    }

    private static function normalize(string $name): string
    {
        $name = Str::lower(Str::ascii($name));
        $name = preg_replace('/\b(branch|sheet|office)\b/', '', $name) ?? $name;

        return preg_replace('/[^a-z]/', '', $name) ?? '';
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flushLookupCache());
        static::deleted(fn () => self::flushLookupCache());
    }
}
