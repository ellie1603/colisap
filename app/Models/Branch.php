<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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

    private static bool $restrictionPaused = false;

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /**
     * The branch the signed-in user is limited to, or null when they see every branch
     * (Administrators, staff without a home branch, console commands).
     */
    public static function restrictedId(): ?int
    {
        return self::$restrictionPaused ? null : Auth::user()?->restrictedBranchId();
    }

    /**
     * Run work that must see every branch whoever is signed in — a masterlist import matches
     * Acct. Numbers across the whole cooperative so a member is never created twice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutRestriction(callable $callback): mixed
    {
        $wasPaused = self::$restrictionPaused;
        self::$restrictionPaused = true;

        try {
            return $callback();
        } finally {
            self::$restrictionPaused = $wasPaused;
        }
    }

    /**
     * Branch options for selects, in official order — only the user's own branch when they are limited to one.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()
            ->where('is_active', true)
            ->when(self::restrictedId(), fn (Builder $query, int $branchId) => $query->whereKey($branchId))
            ->orderBy('sort_order')
            ->pluck('name', 'id')
            ->all();
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
