<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToActiveMember;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Savings ledger. Every entry adjusts the member's savings balance, so the balance shown in
 * every module (monitoring, eligibility, claims) stays in sync no matter where it was recorded.
 */
class SavingsTransaction extends Model
{
    use Auditable, BelongsToActiveMember, HasFactory;

    /**
     * @var array<string, string>
     */
    public const TYPES = [
        'deposit' => 'Deposit',
        'withdrawal' => 'Withdrawal',
        'contribution_deduction' => 'Mortuary contribution deduction',
        'import_adjustment' => 'Masterlist balance update',
    ];

    /**
     * Types that count as account activity for dormancy (when activity is tracked in this system).
     *
     * @var list<string>
     */
    public const ACTIVITY_TYPES = ['deposit', 'withdrawal'];

    protected $fillable = [
        'member_id',
        'type',
        'amount',
        'balance_after',
        'transaction_date',
        'reference_no',
        'remarks',
        'import_batch_id',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'transaction_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (SavingsTransaction $transaction) {
            $transaction->recorded_by ??= auth()->id();

            if ($transaction->type === 'withdrawal' || $transaction->type === 'contribution_deduction') {
                $transaction->amount = -abs((float) $transaction->amount);
            }
        });

        static::created(function (SavingsTransaction $transaction) {
            $transaction->applyToMember((float) $transaction->amount, isNew: true);
        });

        static::updated(function (SavingsTransaction $transaction) {
            if ($transaction->wasChanged('amount')) {
                $transaction->applyToMember((float) $transaction->amount - (float) $transaction->getOriginal('amount'));
            }
        });

        static::deleted(function (SavingsTransaction $transaction) {
            $transaction->applyToMember(-(float) $transaction->amount);
        });
    }

    private function applyToMember(float $delta, bool $isNew = false): void
    {
        $member = Member::find($this->member_id);

        if (! $member) {
            return;
        }

        $member->savings_balance = round((float) $member->savings_balance + $delta, 2);

        if ($isNew && in_array($this->type, self::ACTIVITY_TYPES, true)
            && ($member->last_activity_date === null || $this->transaction_date->gt($member->last_activity_date))) {
            $member->last_activity_date = $this->transaction_date;
        }

        if ($isNew) {
            $this->updateQuietly(['balance_after' => $member->savings_balance]);
        }

        $member->withChangeReason('Savings '.(self::TYPES[$this->type] ?? $this->type), 'system')->save();
        $member->refreshStatus();
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
