<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request to the BMPC ONE monitoring API. Never holds the key, the Authorization header or the response.
 */
#[Fillable(['monitoring_api_key_id', 'endpoint', 'method', 'http_status', 'duration_ms', 'source_ip', 'requested_at'])]
class MonitoringApiLog extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
        ];
    }

    /**
     * Entries older than monitoring.log_retention_days are removed by the daily model:prune.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('requested_at', '<', now()->subDays((int) config('monitoring.log_retention_days', 90)));
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(MonitoringApiKey::class, 'monitoring_api_key_id');
    }
}
