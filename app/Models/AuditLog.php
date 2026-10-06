<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'module',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'description',
        'reason',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    private static bool $recordingPaused = false;

    /**
     * Run a bulk operation without one audit entry per record. The caller is responsible for leaving its own
     * trail (a masterlist import keeps the batch, every source row and the member change history).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutRecording(callable $callback): mixed
    {
        $wasPaused = self::$recordingPaused;
        self::$recordingPaused = true;

        try {
            return $callback();
        } finally {
            self::$recordingPaused = $wasPaused;
        }
    }

    public static function recordingPaused(): bool
    {
        return self::$recordingPaused;
    }

    protected static function booted(): void
    {
        static::creating(fn (AuditLog $log) => $log->created_at ??= now());

        // The audit trail is append-only.
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo('auditable', 'auditable_type', 'auditable_id');
    }
}
