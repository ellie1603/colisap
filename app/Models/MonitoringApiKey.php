<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\MonitoringApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * A BMPC ONE monitoring API key. Only its SHA-256 hash is stored; the full key is shown once, when made.
 */
#[Fillable(['name', 'key_hash', 'key_prefix', 'key_last_four', 'scopes', 'status', 'created_by', 'expires_at', 'last_used_at', 'revoked_at'])]
#[Hidden(['key_hash'])]
class MonitoringApiKey extends Model
{
    /** @use HasFactory<MonitoringApiKeyFactory> */
    use Auditable, HasFactory;

    public const PREFIX = 'bmmon_live_';

    /**
     * Read-only scopes (the standard forbids write scopes for BMPC ONE).
     *
     * @var array<string, string>
     */
    public const SCOPES = [
        'monitoring:read' => 'Health, system and summary',
        'analytics:read' => 'Analytics',
        'reports:read' => 'Report list',
        'activity:read' => 'Recent activity',
    ];

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'active' => 'Active',
        'disabled' => 'Disabled',
        'revoked' => 'Revoked',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Makes a new key and returns it with the raw key, which is never stored and cannot be shown again.
     *
     * @param  list<string>  $scopes
     * @return array{0: self, 1: string}
     */
    public static function generate(string $name, array $scopes, ?\DateTimeInterface $expiresAt = null): array
    {
        $raw = self::PREFIX.Str::random(48);

        $key = self::create([
            'name' => $name,
            'key_hash' => self::hash($raw),
            'key_prefix' => substr($raw, 0, strlen(self::PREFIX) + 4),
            'key_last_four' => substr($raw, -4),
            'scopes' => array_values(array_intersect(array_keys(self::SCOPES), $scopes)),
            'status' => 'active',
            'created_by' => Auth::id(),
            'expires_at' => $expiresAt,
        ]);

        return [$key, $raw];
    }

    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    public static function findByRawKey(string $raw): ?self
    {
        if (! str_starts_with($raw, self::PREFIX)) {
            return null;
        }

        $key = self::query()->where('key_hash', self::hash($raw))->first();

        return $key && hash_equals($key->key_hash, self::hash($raw)) ? $key : null;
    }

    /**
     * Why this key may not be used right now, or null when it may.
     */
    public function unusableReason(): ?string
    {
        return match (true) {
            $this->status === 'revoked' || $this->revoked_at !== null => 'revoked',
            $this->status !== 'active' => 'disabled',
            $this->expires_at !== null && $this->expires_at->isPast() => 'expired',
            default => null,
        };
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /**
     * Records use at most once a minute, without an audit entry for every request.
     */
    public function markUsed(): void
    {
        if ($this->last_used_at === null || $this->last_used_at->lt(now()->subMinute())) {
            $this->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }

    public function revoke(): void
    {
        $this->update(['status' => 'revoked', 'revoked_at' => now()]);
    }

    /**
     * A replacement key with the same name, scopes and expiry; this one is revoked.
     *
     * @return array{0: self, 1: string}
     */
    public function rotate(): array
    {
        $replacement = self::generate($this->name, $this->scopes ?? [], $this->expires_at);
        $this->revoke();

        return $replacement;
    }

    public function maskedKey(): string
    {
        return $this->key_prefix.'…'.$this->key_last_four;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MonitoringApiLog::class);
    }
}
