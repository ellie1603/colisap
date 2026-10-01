<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => $model->recordAudit('created', null, $model->getAttributes()));

        static::updated(function ($model) {
            $changes = Arr::except($model->getChanges(), ['updated_at']);

            if ($changes !== []) {
                $model->recordAudit('updated', array_intersect_key($model->getOriginal(), $changes), $changes);
            }
        });

        static::deleted(fn ($model) => $model->recordAudit('deleted', $model->getAttributes(), null));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn ($model) => $model->recordAudit('restored', null, null));
        }
    }

    /**
     * Write an audit entry. Hidden attributes (passwords, tokens) are never logged.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function recordAudit(string $action, ?array $oldValues, ?array $newValues, ?string $reason = null): void
    {
        $hidden = $this->getHidden();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'module' => Str::headline(class_basename(static::class)),
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'old_values' => $oldValues === null ? null : Arr::except($oldValues, $hidden),
            'new_values' => $newValues === null ? null : Arr::except($newValues, $hidden),
            'reason' => $reason,
            'ip_address' => Request::ip(),
            'user_agent' => Str::limit((string) Request::userAgent(), 250, ''),
        ]);
    }

    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable', 'auditable_type', 'auditable_id');
    }
}
