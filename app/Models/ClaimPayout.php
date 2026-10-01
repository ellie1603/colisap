<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A beneficiary's portion of a settled mortuary benefit.
 */
class ClaimPayout extends Model
{
    use Auditable;

    protected $fillable = [
        'claim_id',
        'beneficiary_id',
        'beneficiary_name',
        'share_percentage',
        'amount',
        'released_at',
        'released_by',
        'reference_no',
    ];

    protected $casts = [
        'share_percentage' => 'decimal:2',
        'amount' => 'decimal:2',
        'released_at' => 'datetime',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class)->withTrashed();
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
