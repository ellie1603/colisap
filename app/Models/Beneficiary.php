<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Beneficiary extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'member_id',
        'full_name',
        'relationship',
        'birthdate',
        'contact_number',
        'address',
        'share_percentage',
        'is_active',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'share_percentage' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
