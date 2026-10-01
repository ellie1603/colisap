<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\Colisap\MemberStatusEngine;
use App\Services\Policy\PolicySettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class Member extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'waiting' => 'Waiting',
        'active' => 'Active',
        'dormant' => 'Dormant',
        'deceased' => 'Deceased',
        'terminated' => 'Terminated',
        'withdrawn' => 'Withdrawn',
    ];

    /**
     * Statuses the status engine never changes automatically.
     *
     * @var list<string>
     */
    public const TERMINAL_STATUSES = ['deceased', 'terminated', 'withdrawn'];

    /**
     * @var array<string, string>
     */
    public const CATEGORIES = [
        '40000' => '40K',
        '60000' => '60K',
    ];

    /**
     * Member segmentation — independent of the 40K/60K benefit category.
     *
     * @var array<string, string>
     */
    public const SEGMENTS = [
        'D' => 'Diamond',
        'G' => 'Gold',
        'S' => 'Silver',
        'R' => 'Regular',
    ];

    /**
     * Fields whose changes are written to member_histories.
     *
     * @var list<string>
     */
    private const TRACKED_FIELDS = ['status', 'category', 'segment', 'branch_id'];

    protected $fillable = [
        'account_no',
        'account_name',
        'branch_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'birthdate',
        'sex',
        'contact_number',
        'email',
        'address',
        'application_date',
        'approval_date',
        'status',
        'category',
        'segment',
        'activated_at',
        'category_upgrade_requested_at',
        'savings_balance',
        'savings_balance_as_of',
        'savings_account_status',
        'last_activity_date',
        'dormant_since',
        'date_deceased',
        'terminated_at',
        'termination_reason',
        'withdrawn_at',
        'withdrawal_reason',
        'withdrawal_document',
        'remarks',
        'import_batch_id',
        'source_sheet',
        'source_row',
        'source_values',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'application_date' => 'date',
        'approval_date' => 'date',
        'activated_at' => 'date',
        'category_upgrade_requested_at' => 'date',
        'savings_balance' => 'decimal:2',
        'savings_balance_as_of' => 'date',
        'last_activity_date' => 'date',
        'dormant_since' => 'date',
        'date_deceased' => 'date',
        'terminated_at' => 'date',
        'withdrawn_at' => 'date',
        'source_values' => 'array',
    ];

    private ?string $changeReason = null;

    private string $changeSource = 'manual';

    protected static function booted(): void
    {
        static::creating(function (Member $member) {
            $member->created_by ??= Auth::id();
            $member->status ??= 'waiting';
            $member->category ??= '40000';
            $member->account_name ??= $member->composeAccountName();
        });

        static::saving(function (Member $member) {
            if (Auth::id()) {
                $member->updated_by = Auth::id();
            }
        });

        static::created(function (Member $member) {
            $member->recordHistory('status', null, $member->status, $member->changeReason ?? 'Member record created');
        });

        static::updated(function (Member $member) {
            foreach (self::TRACKED_FIELDS as $field) {
                if ($member->wasChanged($field)) {
                    $member->recordHistory($field, $member->getOriginal($field), $member->getAttribute($field), $member->changeReason);
                }
            }

            $member->changeReason = null;
            $member->changeSource = 'manual';
        });
    }

    /**
     * Attach a reason/source to the next save so history and audit entries explain the change.
     */
    public function withChangeReason(?string $reason, string $source = 'manual'): static
    {
        $this->changeReason = $reason;
        $this->changeSource = $source;

        return $this;
    }

    public function recordHistory(string $field, mixed $old, mixed $new, ?string $reason = null): void
    {
        if ($field === 'branch_id') {
            $field = 'branch';
            $old = $old ? Branch::find($old)?->name : null;
            $new = $new ? Branch::find($new)?->name : null;
        }

        $this->histories()->create([
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'reason' => $reason,
            'source' => $this->changeSource,
            'changed_by' => Auth::id(),
            'changed_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------- Labels & display

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'waiting' => 'info',
            'dormant' => 'warning',
            'terminated' => 'danger',
            default => 'gray',
        };
    }

    public static function statusIcon(?string $status): string
    {
        return match ($status) {
            'active' => 'heroicon-m-check-circle',
            'waiting' => 'heroicon-m-clock',
            'dormant' => 'heroicon-m-pause-circle',
            'deceased' => 'heroicon-m-minus-circle',
            'terminated' => 'heroicon-m-x-circle',
            'withdrawn' => 'heroicon-m-arrow-left-start-on-rectangle',
            default => 'heroicon-m-question-mark-circle',
        };
    }

    public static function segmentLabel(?string $segment): string
    {
        return self::SEGMENTS[$segment] ?? 'Unassigned';
    }

    public static function categoryLabel(?string $category): string
    {
        return self::CATEGORIES[$category] ?? '—';
    }

    public function fullName(): string
    {
        return trim(preg_replace('/\s+/', ' ', "{$this->first_name} {$this->middle_name} {$this->last_name} {$this->suffix}") ?? '');
    }

    public function getFullNameAttribute(): string
    {
        return $this->fullName();
    }

    public function composeAccountName(): string
    {
        return mb_strtoupper(trim("{$this->last_name}, {$this->first_name} {$this->middle_name} {$this->suffix}"));
    }

    public function age(): ?int
    {
        return $this->birthdate?->age;
    }

    // ---------------------------------------------------------------- Relationships

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class)->orderBy('priority');
    }

    public function activeBeneficiaries(): HasMany
    {
        return $this->beneficiaries()->where('is_active', true);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    public function savingsTransactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(MemberHistory::class)->latest('changed_at')->latest('id');
    }

    public function replenishmentNotices(): HasMany
    {
        return $this->hasMany(ReplenishmentNotice::class);
    }

    public function openReplenishmentNotice(): HasOne
    {
        return $this->hasOne(ReplenishmentNotice::class)->where('status', 'open')->latestOfMany();
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    // ---------------------------------------------------------------- Scopes

    /**
     * Match every word of the search against Acct. Number, account name or any name part.
     *
     * @param  Builder<Member>  $query
     */
    public function scopeSearchName(Builder $query, string $search): void
    {
        foreach (preg_split('/[\s,]+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $query->where(fn (Builder $query) => $query
                ->where('account_no', 'like', "%{$word}%")
                ->orWhere('account_name', 'like', "%{$word}%")
                ->orWhere('first_name', 'like', "%{$word}%")
                ->orWhere('middle_name', 'like', "%{$word}%")
                ->orWhere('last_name', 'like', "%{$word}%"));
        }
    }

    /**
     * @param  Builder<Member>  $query
     */
    public function scopeParticipating(Builder $query): void
    {
        $query->whereNotIn('status', self::TERMINAL_STATUSES);
    }

    // ---------------------------------------------------------------- Policy calculations

    private function policy(): PolicySettings
    {
        return app(PolicySettings::class);
    }

    /**
     * Approval date + effectivity period (Policy III.1).
     */
    public function effectivityDate(): ?Carbon
    {
        return $this->approval_date?->copy()->addDays($this->policy()->int('effectivity_days'));
    }

    /**
     * Days until the member becomes effective (0 once effective, null without approval date).
     */
    public function daysUntilEffective(): ?int
    {
        $effectivity = $this->effectivityDate();

        if (! $effectivity) {
            return null;
        }

        return max(0, (int) Carbon::today()->diffInDays($effectivity, false));
    }

    /**
     * Upgrade request date + upgrade waiting period (Policy III.3).
     */
    public function upgradeEligibleDate(): ?Carbon
    {
        return $this->category_upgrade_requested_at?->copy()->addDays($this->policy()->int('upgrade_wait_days'));
    }

    public function upgradeStatus(): string
    {
        if ($this->category === '60000') {
            return 'upgraded';
        }

        $eligibleDate = $this->upgradeEligibleDate();

        return match (true) {
            $eligibleDate === null => 'none',
            Carbon::today()->gte($eligibleDate) => 'eligible',
            default => 'pending',
        };
    }

    public function minimumBalance(): float
    {
        return $this->policy()->minimumBalanceFor($this->category);
    }

    public function balanceShortfall(): float
    {
        return max(0, round($this->minimumBalance() - (float) $this->savings_balance, 2));
    }

    public function meetsMinimumBalance(): bool
    {
        return (float) $this->savings_balance >= $this->minimumBalance();
    }

    public function benefitAmount(): float
    {
        return $this->policy()->benefitFor($this->category);
    }

    public function beneficiaryCount(): int
    {
        if (array_key_exists('active_beneficiaries_count', $this->attributes)) {
            return (int) $this->attributes['active_beneficiaries_count'];
        }

        return $this->activeBeneficiaries()->count();
    }

    public function hasCompleteBeneficiaries(): bool
    {
        $count = $this->beneficiaryCount();

        return $count >= $this->policy()->int('min_beneficiaries') && $count <= $this->policy()->int('max_beneficiaries');
    }

    /**
     * Covered for the mortuary benefit right now: effective (active), meets maintaining balance
     * and has the required beneficiaries.
     */
    public function isEligible(): bool
    {
        return $this->status === 'active' && $this->meetsMinimumBalance() && $this->hasCompleteBeneficiaries();
    }

    /**
     * Human-readable reasons the member is not currently eligible.
     *
     * @return list<string>
     */
    public function eligibilityIssues(): array
    {
        $issues = [];

        if ($this->status === 'waiting') {
            $issues[] = $this->approval_date
                ? "Waiting period: {$this->daysUntilEffective()} day(s) until effective on {$this->effectivityDate()->toFormattedDateString()}"
                : 'No approval date recorded';
        } elseif ($this->status !== 'active') {
            $issues[] = 'Status is '.(self::STATUSES[$this->status] ?? $this->status);
        }

        if (! $this->meetsMinimumBalance()) {
            $issues[] = 'Savings below ₱'.number_format($this->minimumBalance(), 2).' maintaining balance (short ₱'.number_format($this->balanceShortfall(), 2).')';
        }

        if (! $this->hasCompleteBeneficiaries()) {
            $issues[] = $this->beneficiaryCount() === 0 ? 'Beneficiary information incomplete' : 'Beneficiary count outside the allowed range';
        }

        return $issues;
    }

    // ---------------------------------------------------------------- Actions

    /**
     * Re-evaluate waiting/active/dormant, replenishment, terminations and upgrades per policy.
     */
    public function refreshStatus(): bool
    {
        return app(MemberStatusEngine::class)->evaluate($this);
    }

    public function requestCategoryUpgrade(?Carbon $requestedAt = null): void
    {
        if ($this->category !== '40000' || $this->status !== 'active') {
            throw new \RuntimeException('Only active 40K members may request a category upgrade.');
        }

        $this->update(['category_upgrade_requested_at' => $requestedAt ?? Carbon::today()]);
    }

    /**
     * Move an eligible member to 60K once the upgrade waiting period has passed.
     */
    public function applyCategoryUpgradeIfEligible(): bool
    {
        if ($this->upgradeStatus() !== 'eligible' || (float) $this->savings_balance < $this->policy()->minimumBalanceFor('60000')) {
            return false;
        }

        $this->withChangeReason('Upgrade waiting period completed (Policy III.3)')
            ->fill(['category' => '60000', 'category_upgrade_requested_at' => null])
            ->save();

        return true;
    }

    /**
     * Record the member's death (e.g. when a death-benefit claim is filed).
     */
    public function markDeceased(Carbon|string|null $dateOfDeath): void
    {
        $this->status = 'deceased';
        $this->date_deceased = $dateOfDeath ?? $this->date_deceased ?? Carbon::now();

        if ($this->isDirty()) {
            $this->withChangeReason('Death recorded')->save();
        }
    }

    /**
     * Branch options keyed by id for filters and selects.
     *
     * @return array<int, string>
     */
    public static function branchOptions(): array
    {
        return Branch::options();
    }
}
