<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EligibilityRule extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'name',
        'description',
        'min_membership_months',
        'min_contribution_balance',
        'max_missed_contributions',
        'benefit_amount',
        'is_active',
    ];

    protected $casts = [
        'min_contribution_balance' => 'decimal:2',
        'benefit_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
