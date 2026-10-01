<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToActiveMember;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Beneficiary extends Model
{
    use Auditable, BelongsToActiveMember, HasFactory, SoftDeletes;

    /**
     * @var array<string, string>
     */
    public const RELATIONSHIPS = [
        'Spouse' => 'Spouse',
        'Child' => 'Child',
        'Parent' => 'Parent',
        'Sibling' => 'Sibling',
        'Grandchild' => 'Grandchild',
        'Relative' => 'Other relative',
        'Other' => 'Other',
    ];

    /**
     * @var array<string, string>
     */
    public const ID_TYPES = [
        'PhilSys' => 'PhilSys National ID',
        'Passport' => 'Passport',
        'Drivers License' => "Driver's License",
        'SSS' => 'SSS / UMID',
        'Voters ID' => "Voter's ID",
        'Birth Certificate' => 'Birth Certificate',
        'Other' => 'Other',
    ];

    protected $fillable = [
        'member_id',
        'full_name',
        'relationship',
        'birthdate',
        'contact_number',
        'address',
        'share_percentage',
        'priority',
        'id_type',
        'id_number',
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
