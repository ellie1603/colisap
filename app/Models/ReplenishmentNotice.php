<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToActiveMember;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 15-day notice to restore the maintaining balance (Policy II.4, VII.1, VII.3).
 */
class ReplenishmentNotice extends Model
{
    use Auditable, BelongsToActiveMember, HasFactory;

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'open' => 'Open',
        'replenished' => 'Replenished',
        'terminated' => 'Expired — terminated',
        'downgraded' => 'Expired — downgraded to 40K',
        'cancelled' => 'Cancelled',
    ];

    protected $fillable = [
        'member_id',
        'category',
        'required_balance',
        'balance_at_notice',
        'shortfall',
        'notice_date',
        'deadline',
        'status',
        'resolved_at',
        'resolved_by',
        'remarks',
        'issued_by',
    ];

    protected $casts = [
        'required_balance' => 'decimal:2',
        'balance_at_notice' => 'decimal:2',
        'shortfall' => 'decimal:2',
        'notice_date' => 'date',
        'deadline' => 'date',
        'resolved_at' => 'date',
    ];

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'open' => 'warning',
            'replenished' => 'success',
            'terminated', 'downgraded' => 'danger',
            default => 'gray',
        };
    }

    public function daysRemaining(): int
    {
        return (int) Carbon::today()->diffInDays($this->deadline, false);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && Carbon::today()->gt($this->deadline);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
