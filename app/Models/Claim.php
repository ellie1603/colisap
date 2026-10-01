<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToActiveMember;
use App\Services\Policy\PolicySettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Mortuary claim (Policy IV and XII): gross benefit by category, less outstanding loans and
 * other cooperative obligations, settled to the beneficiaries by share.
 */
class Claim extends Model
{
    use Auditable, BelongsToActiveMember, HasFactory, SoftDeletes;

    public const STATUSES = [
        'submitted' => 'Submitted',
        'under_review' => 'Under Review',
        'approved' => 'Approved',
        'settled' => 'Settled',
        'rejected' => 'Rejected',
    ];

    protected $fillable = [
        'claim_no',
        'member_id',
        'beneficiary_id',
        'category',
        'date_of_death',
        'date_filed',
        'status',
        'gross_benefit',
        'outstanding_loan',
        'other_obligations',
        'net_benefit',
        'death_certificate_verified',
        'certificate_verified',
        'eligibility_verified',
        'eligibility_notes',
        'reviewed_by',
        'reviewed_at',
        'approved_by',
        'approved_at',
        'settled_at',
        'settled_by',
        'rejection_reason',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'date_of_death' => 'date',
        'date_filed' => 'date',
        'gross_benefit' => 'decimal:2',
        'outstanding_loan' => 'decimal:2',
        'other_obligations' => 'decimal:2',
        'net_benefit' => 'decimal:2',
        'death_certificate_verified' => 'boolean',
        'certificate_verified' => 'boolean',
        'eligibility_verified' => 'boolean',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'under_review' => 'warning',
            'approved' => 'success',
            'settled' => 'info',
            'rejected' => 'danger',
            default => 'gray',
        };
    }

    protected static function booted(): void
    {
        static::creating(function (Claim $claim) {
            $claim->claim_no ??= static::generateClaimNo();
            $claim->created_by ??= Auth::id();
            $claim->category ??= Member::withTrashed()->whereKey($claim->member_id)->value('category') ?? '40000';
            $claim->gross_benefit ??= app(PolicySettings::class)->benefitFor($claim->category);
        });

        static::saving(function (Claim $claim) {
            if ($claim->exists) {
                $claim->outstanding_loan = (float) $claim->deductions()->where('type', 'loan')->sum('amount');
                $claim->other_obligations = (float) $claim->deductions()->where('type', 'obligation')->sum('amount');
            }

            $claim->net_benefit = $claim->calculateNetBenefit();
        });

        static::saved(function (Claim $claim) {
            if ($claim->wasRecentlyCreated || $claim->wasChanged(['member_id', 'date_of_death'])) {
                Member::find($claim->member_id)?->markDeceased($claim->date_of_death);
            }
        });
    }

    public static function generateClaimNo(): string
    {
        $year = now()->year;

        return DB::transaction(function () use ($year) {
            $sequence = static::withTrashed()->withoutGlobalScopes()->whereYear('created_at', $year)->lockForUpdate()->count() + 1;

            while (static::withTrashed()->withoutGlobalScopes()->where('claim_no', sprintf('CLM-%d-%05d', $year, $sequence))->exists()) {
                $sequence++;
            }

            return sprintf('CLM-%d-%05d', $year, $sequence);
        });
    }

    /**
     * Gross benefit − outstanding loans − other cooperative obligations (never below zero).
     * The two deduction totals are summed from the itemized claim_deductions rows.
     */
    public function calculateNetBenefit(): float
    {
        return max(0, round((float) $this->gross_benefit - (float) $this->outstanding_loan - (float) $this->other_obligations, 2));
    }

    public function totalDeductions(): float
    {
        return round((float) $this->gross_benefit - (float) $this->net_benefit, 2);
    }

    /**
     * Policy checks for the claim; empty when the member was covered at the date of death.
     *
     * @return list<string>
     */
    public function eligibilityIssues(): array
    {
        $member = $this->member;

        if (! $member) {
            return ['Member record not found'];
        }

        $issues = [];
        $effectivity = $member->effectivityDate();

        if (! $effectivity) {
            $issues[] = 'Member has no approval date';
        } elseif ($this->date_of_death && $this->date_of_death->lt($effectivity)) {
            $issues[] = 'Death occurred during the 180-day waiting period (effective '.$effectivity->toFormattedDateString().')';
        }

        if ($member->terminated_at && $this->date_of_death && $member->terminated_at->lte($this->date_of_death)) {
            $issues[] = 'Participation was terminated on '.$member->terminated_at->toFormattedDateString().' ('.$member->termination_reason.')';
        }

        if ($member->withdrawn_at && $this->date_of_death && $member->withdrawn_at->lte($this->date_of_death)) {
            $issues[] = 'Member withdrew on '.$member->withdrawn_at->toFormattedDateString();
        }

        if ($member->activeBeneficiaries()->doesntExist()) {
            $issues[] = 'Member has no active beneficiaries';
        }

        if (! $this->death_certificate_verified) {
            $issues[] = 'Death certificate not yet verified';
        }

        if (! $this->certificate_verified) {
            $issues[] = 'COLISAP application/certificate not yet verified';
        }

        return $issues;
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

    public function deductions(): HasMany
    {
        return $this->hasMany(ClaimDeduction::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(ClaimPayout::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
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

    /**
     * Approve and split the net benefit across the member's active beneficiaries by share.
     */
    public function approve(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->update([
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'eligibility_verified' => true,
            ]);

            $this->generatePayouts();
        });
    }

    public function generatePayouts(): void
    {
        $this->payouts()->whereNull('released_at')->delete();

        $beneficiaries = $this->member->activeBeneficiaries()->get();

        if ($beneficiaries->isEmpty() && $this->beneficiary) {
            $beneficiaries = collect([$this->beneficiary->setAttribute('share_percentage', 100)]);
        }

        $totalShare = (float) $beneficiaries->sum('share_percentage') ?: 100;
        $net = (float) $this->net_benefit;
        $allocated = 0.0;

        foreach ($beneficiaries->values() as $index => $beneficiary) {
            $share = (float) ($beneficiary->share_percentage ?: 100 / $beneficiaries->count());
            $amount = $index === $beneficiaries->count() - 1
                ? round($net - $allocated, 2)
                : round($net * $share / $totalShare, 2);
            $allocated += $amount;

            $this->payouts()->create([
                'beneficiary_id' => $beneficiary->id,
                'beneficiary_name' => $beneficiary->full_name,
                'share_percentage' => $share,
                'amount' => $amount,
            ]);
        }
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

    public function settle(User $user, ?string $reference = null): void
    {
        DB::transaction(function () use ($user, $reference) {
            $this->update([
                'status' => 'settled',
                'settled_at' => now(),
                'settled_by' => $user->id,
            ]);

            $this->payouts()->whereNull('released_at')->update([
                'released_at' => Carbon::now(),
                'released_by' => $user->id,
                'reference_no' => $reference,
            ]);
        });
    }
}
