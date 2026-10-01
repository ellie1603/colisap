<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable history of status, category, segmentation and branch changes.
 */
class MemberHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'member_id',
        'field',
        'old_value',
        'new_value',
        'reason',
        'source',
        'changed_by',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function displayValue(?string $value): string
    {
        return match ($this->field) {
            'status' => Member::STATUSES[$value] ?? ($value ?? '—'),
            'category' => Member::CATEGORIES[$value] ?? ($value ?? '—'),
            'segment' => $value === null ? 'Unassigned' : Member::segmentLabel($value),
            default => $value ?? '—',
        };
    }
}
