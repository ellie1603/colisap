<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Claim extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUSES = [
        'submitted' => 'Submitted',
        'under_review' => 'Under Review',
        'approved' => 'Approved',
        'paid' => 'Paid',
        'rejected' => 'Rejected',
    ];

    protected $fillable = [
        'claim_no',
        'member_id',
        'beneficiary_id',
        'date_of_death',
        'date_filed',
        'status',
        'claim_amount',
        'approved_amount',
        'reviewed_by',
        'reviewed_at',
        'approved_by',
        'approved_at',
        'paid_at',
        'rejection_reason',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'date_of_death' => 'date',
        'date_filed' => 'date',
        'claim_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Claim $claim) {
            $claim->claim_no ??= static::generateClaimNo();
            $claim->created_by ??= Auth::id();
        });
    }

    public static function generateClaimNo(): string
    {
        $year = now()->year;

        return DB::transaction(function () use ($year) {
            $count = static::withTrashed()->whereYear('created_at', $year)->lockForUpdate()->count();

            return sprintf('CLM-%d-%05d', $year, $count + 1);
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ClaimDocument::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function markUnderReview(User $user): void
    {
        $this->update([
            'status' => 'under_review',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);
    }

    public function approve(User $user, ?float $amount = null): void
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'approved_amount' => $amount ?? $this->claim_amount,
        ]);
    }

    public function reject(User $user, string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    public function markPaid(): void
    {
        $this->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }
}
