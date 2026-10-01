<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staged masterlist row: normalized data, original cell values, validation result and outcome.
 */
class ImportRow extends Model
{
    /**
     * @var array<string, string>
     */
    public const ACTIONS = [
        'new' => 'New member',
        'update' => 'Update existing',
        'duplicate' => 'Duplicate in file',
        'invalid' => 'Invalid',
    ];

    protected $fillable = [
        'import_batch_id',
        'sheet',
        'row_number',
        'account_no',
        'account_name',
        'branch_id',
        'action',
        'data',
        'raw',
        'errors',
        'warnings',
        'status',
        'result',
        'member_id',
    ];

    protected $casts = [
        'data' => 'array',
        'raw' => 'array',
        'errors' => 'array',
        'warnings' => 'array',
    ];

    public static function actionColor(?string $action): string
    {
        return match ($action) {
            'new' => 'success',
            'update' => 'info',
            'duplicate' => 'warning',
            default => 'danger',
        };
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
