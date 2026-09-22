<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contribution extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'member_id',
        'amount',
        'contribution_date',
        'reference_no',
        'remarks',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'contribution_date' => 'date',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
