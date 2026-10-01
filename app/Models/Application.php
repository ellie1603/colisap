<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToActiveMember;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * COLISAP application or re-application. Application status (pending/approved/rejected) is kept
 * separate from the member's COLISAP status (waiting/active/...).
 */
class Application extends Model
{
    use Auditable, BelongsToActiveMember, HasFactory, SoftDeletes;

    /**
     * @var array<string, string>
     */
    public const TYPES = [
        'new' => 'New application',
        'reapplication' => 'Re-application',
    ];

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    protected $fillable = [
        'member_id',
        'type',
        'category',
        'application_date',
        'status',
        'approved_at',
        'approved_by',
        'rejection_reason',
        'good_health_declared',
        'age_verified',
        'balance_verified',
        'fee_amount',
        'fee_paid',
        'previous_status',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'application_date' => 'date',
        'approved_at' => 'date',
        'good_health_declared' => 'boolean',
        'age_verified' => 'boolean',
        'balance_verified' => 'boolean',
        'fee_amount' => 'decimal:2',
        'fee_paid' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn (Application $application) => $application->created_by ??= Auth::id());
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'approved' => 'success',
            'rejected' => 'danger',
            default => 'warning',
        };
    }

    /**
     * Qualification checklist items still unchecked (Policy II, X).
     *
     * @return list<string>
     */
    public function missingRequirements(): array
    {
        return array_values(array_filter([
            $this->good_health_declared ? null : 'Good-health declaration',
            $this->age_verified ? null : 'Age verification (not over 59 years 6 months)',
            $this->balance_verified ? null : 'Maintaining balance verified',
            $this->type === 'reapplication' && ! $this->fee_paid ? 'Re-application fee paid' : null,
        ]));
    }

    /**
     * Approve: the member enters the waiting period from the approval date.
     */
    public function approve(?Carbon $approvedAt = null): void
    {
        $approvedAt ??= Carbon::today();

        $this->update([
            'status' => 'approved',
            'approved_at' => $approvedAt,
            'approved_by' => Auth::id(),
        ]);

        $member = $this->member;

        $member->withChangeReason(self::TYPES[$this->type].' approved')->fill([
            'application_date' => $this->application_date,
            'approval_date' => $approvedAt,
            'category' => $this->category,
            'status' => 'waiting',
            'activated_at' => null,
            'dormant_since' => null,
            'terminated_at' => null,
            'termination_reason' => null,
            'withdrawn_at' => null,
            'withdrawal_reason' => null,
            'category_upgrade_requested_at' => null,
        ])->save();

        $member->refreshStatus();
    }

    public function reject(string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'approved_by' => Auth::id(),
            'approved_at' => Carbon::today(),
        ]);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
