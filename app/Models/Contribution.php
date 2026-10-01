<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToActiveMember;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A participant's mortuary contribution towards a claim (Policy III.2 and VI).
 */
class Contribution extends Model
{
    use Auditable, BelongsToActiveMember, HasFactory;

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'pending' => 'Pending',
        'deducted' => 'Deducted from savings',
        'waived' => 'Shouldered by coop',
    ];

    protected $fillable = [
        'member_id',
        'claim_id',
        'amount',
        'member_share',
        'coop_share',
        'segment',
        'contribution_date',
        'reference_no',
        'status',
        'savings_transaction_id',
        'remarks',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'member_share' => 'decimal:2',
        'coop_share' => 'decimal:2',
        'contribution_date' => 'date',
    ];

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'deducted' => 'success',
            'waived' => 'info',
            default => 'warning',
        };
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function savingsTransaction(): BelongsTo
    {
        return $this->belongsTo(SavingsTransaction::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
