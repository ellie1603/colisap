<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Member extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'member_no',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'birthdate',
        'sex',
        'contact_number',
        'email',
        'address',
        'membership_date',
        'status',
        'date_deceased',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'membership_date' => 'date',
        'date_deceased' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(fn (Member $member) => $member->created_by ??= Auth::id());
    }

    public static function generateNextMemberNo(): string
    {
        return DB::transaction(function () {
            $count = static::withTrashed()->lockForUpdate()->count();

            return sprintf('BMPC-%05d', $count + 1);
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class);
    }

    public function activeBeneficiaries(): HasMany
    {
        return $this->beneficiaries()->where('is_active', true);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name} {$this->suffix}");
    }

    public function getFullNameAttribute(): string
    {
        return $this->fullName();
    }

    public function contributionBalance(): float
    {
        return (float) $this->contributions()->sum('amount');
    }

    public function membershipMonths(): int
    {
        if (! $this->membership_date) {
            return 0;
        }

        return (int) $this->membership_date->diffInMonths(Carbon::now());
    }

    public function isEligible(): bool
    {
        $rule = EligibilityRule::where('is_active', true)->first();

        if (! $rule) {
            return false;
        }

        if ($this->status !== 'active') {
            return false;
        }

        if ($this->membershipMonths() < $rule->min_membership_months) {
            return false;
        }

        if ($this->contributionBalance() < $rule->min_contribution_balance) {
            return false;
        }

        return true;
    }
}
