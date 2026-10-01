<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Itemized deduction from a mortuary benefit (outstanding loan or other cooperative obligation).
 */
class ClaimDeduction extends Model
{
    use Auditable;

    /**
     * @var array<string, string>
     */
    public const TYPES = [
        'loan' => 'Outstanding loan',
        'obligation' => 'Other cooperative obligation',
    ];

    protected $fillable = [
        'claim_id',
        'type',
        'description',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        $recalculate = fn (ClaimDeduction $deduction) => $deduction->claim?->save();

        static::saved($recalculate);
        static::deleted($recalculate);
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }
}
