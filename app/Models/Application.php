<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * COLISAP application or re-application. An application stands on its own: it carries the applicant's
 * details and has no member record until it is approved — only then is the member created (a re-application
 * updates the member that already exists). Application status (pending/approved/rejected) is kept separate
 * from the member's COLISAP status (waiting/active/...).
 */
class Application extends Model
{
    use Auditable, HasFactory, SoftDeletes;

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
        'account_no',
        'last_name',
        'first_name',
        'middle_name',
        'branch_id',
        'birthdate',
        'contact_number',
        'savings_balance',
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
        'birthdate' => 'date',
        'savings_balance' => 'decimal:2',
        'good_health_declared' => 'boolean',
        'age_verified' => 'boolean',
        'balance_verified' => 'boolean',
        'fee_amount' => 'decimal:2',
        'fee_paid' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Listed when the applicant is not a member yet, or when their member record is not archived.
        // Staff limited to one branch see only that branch's applications either way.
        static::addGlobalScope('visible', function (Builder $query) {
            $query->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->whereNull($query->qualifyColumn('member_id'))
                    ->when(Branch::restrictedId(), fn (Builder $query, int $branchId) => $query->where($query->qualifyColumn('branch_id'), $branchId)))
                // "…or its member is one this user may see", as a list the database builds once (measured twice
                // as fast as checking the member of every application row by row).
                ->orWhereIn($query->qualifyColumn('member_id'), Member::query()->select('id')));
        });

        static::creating(fn (Application $application) => $application->created_by ??= Auth::id());
    }

    // ---------------------------------------------------------------- No double applications

    /**
     * Why an application of this type cannot be filed for this Acct. Number right now, or null when it can.
     * One application is open at a time; a rejected one is reopened instead of filed again; a new application
     * is only for someone who is not a member yet; a re-application only for a member whose participation ended.
     */
    public static function filingProblem(string $type, ?string $accountNo, ?Member $member = null): ?string
    {
        $accountNo = trim((string) $accountNo);

        if ($accountNo === '' && $member === null) {
            return null;
        }

        // Checked across every branch: an Acct. Number belongs to one person in the whole cooperative.
        [$member, $applications] = Branch::withoutRestriction(function () use ($accountNo, $member) {
            $member ??= Member::withTrashed()->where('account_no', $accountNo)->first();

            $applications = self::withoutGlobalScope('visible')
                ->where(fn (Builder $query) => $query
                    ->when($member, fn (Builder $query) => $query->where('member_id', $member->id))
                    ->when($accountNo !== '', fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query
                        ->whereNull('member_id')
                        ->where('account_no', $accountNo))))
                ->orderByDesc('id')
                ->get(['id', 'type', 'status']);

            return [$member, $applications];
        });

        $label = strtolower(self::TYPES[$type] ?? 'application');

        if ($applications->contains('status', 'pending')) {
            return 'There is already a pending application for this Acct. Number. Open it to approve or reject it instead of filing another one.';
        }

        if ($applications->firstWhere('type', $type)?->status === 'rejected') {
            return "The {$label} for this Acct. Number was rejected. Reopen that application instead of filing a second one.";
        }

        if ($type === 'new' && $member !== null) {
            return "This Acct. Number already belongs to a member ({$member->account_name}). If their participation has ended, file a re-application instead.";
        }

        if ($type === 'reapplication' && $member === null) {
            return 'No member has this Acct. Number. File a new application instead.';
        }

        if ($type === 'reapplication' && ! in_array($member->status, ['terminated', 'withdrawn'], true)) {
            return 'A re-application is only for a member whose participation was terminated or withdrawn.';
        }

        return null;
    }

    /**
     * The same check for a member already in the system (used by "Start re-application" on a member's profile).
     */
    public static function blockingReason(Member $member, string $type): ?string
    {
        return self::filingProblem($type, $member->account_no, $member);
    }

    // ---------------------------------------------------------------- Display

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'approved' => 'success',
            'rejected' => 'danger',
            default => 'warning',
        };
    }

    /**
     * Once there is a member record it is the source of truth; before that, what the application form recorded.
     */
    public function applicantAccountNo(): ?string
    {
        return $this->member?->account_no ?? $this->account_no;
    }

    public function applicantName(): string
    {
        return $this->member?->account_name
            ?? mb_strtoupper(trim(preg_replace('/\s+/', ' ', "{$this->last_name}, {$this->first_name} {$this->middle_name}") ?? '', ' ,'));
    }

    public function applicantBranchName(): ?string
    {
        return ($this->member?->branch ?? $this->branch)?->name;
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

    // ---------------------------------------------------------------- Decisions

    /**
     * Approve: the applicant becomes a member (or the returning member is reinstated) and enters the
     * waiting period from the approval date.
     */
    public function approve(?Carbon $approvedAt = null): void
    {
        $approvedAt ??= Carbon::today();
        $member = $this->member ?? $this->createMemberFromApplicant($approvedAt);

        $this->update([
            'member_id' => $member->id,
            'status' => 'approved',
            'approved_at' => $approvedAt,
            'approved_by' => Auth::id(),
        ]);

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
        $this->setRelation('member', $member);
    }

    /**
     * The member record of a new applicant, written only now that the application is approved.
     * The savings balance declared on the application becomes the member's opening deposit.
     */
    private function createMemberFromApplicant(Carbon $approvedAt): Member
    {
        $member = Member::create([
            'account_no' => $this->account_no,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'branch_id' => $this->branch_id,
            'birthdate' => $this->birthdate,
            'contact_number' => $this->contact_number,
            'application_date' => $this->application_date,
            'category' => $this->category,
            'status' => 'waiting',
        ]);

        if ((float) $this->savings_balance > 0) {
            SavingsTransaction::create([
                'member_id' => $member->id,
                'type' => 'deposit',
                'amount' => $this->savings_balance,
                'transaction_date' => $approvedAt,
                'remarks' => 'Opening balance declared on the application',
            ]);
        }

        return $member->refresh();
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

    /**
     * Put a rejected application back to pending so it can be reviewed again — used instead of a second application.
     */
    public function reopen(): void
    {
        $this->update([
            'status' => 'pending',
            'rejection_reason' => null,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    // ---------------------------------------------------------------- Relationships

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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
