<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => $model->recordAudit('created', null, $model->getAttributes()));

        static::updated(fn ($model) => $model->recordAudit(
            'updated',
            array_intersect_key($model->getOriginal(), $model->getChanges()),
            $model->getChanges()
        ));

        static::deleted(fn ($model) => $model->recordAudit('deleted', $model->getAttributes(), null));
    }

    protected function recordAudit(string $action, ?array $oldValues, ?array $newValues): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
        ]);
    }

    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable', 'auditable_type', 'auditable_id');
    }
}
