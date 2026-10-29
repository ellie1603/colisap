<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\BmpcMemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member of Barbaza MPC, from the coop's own member list — the people who may join COLISAP.
 * Separate from Member (a COLISAP participant): a new or active COLISAP transaction is filled in from here.
 */
class BmpcMember extends Model
{
    /** @use HasFactory<BmpcMemberFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'account_no',
        'account_name',
        'last_name',
        'first_name',
        'middle_name',
        'suffix',
        'branch_id',
        'birthdate',
        'sex',
        'contact_number',
        'address',
        'savings_balance',
        'import_batch_id',
        'imported_by',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'savings_balance' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Staff with a home branch only see and look up that branch's members.
        static::addGlobalScope('homeBranch', function (Builder $query) {
            if ($branchId = Branch::restrictedId()) {
                $query->where($query->qualifyColumn('branch_id'), $branchId);
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The upload that first added this member.
     */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    /**
     * @param  Builder<BmpcMember>  $query
     */
    public function scopeSearchName(Builder $query, string $search): void
    {
        foreach (preg_split('/[\s,]+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $query->where(fn (Builder $query) => $query
                ->where('account_no', 'like', "%{$word}%")
                ->orWhere('account_name', 'like', "%{$word}%")
                ->orWhere('first_name', 'like', "%{$word}%")
                ->orWhere('middle_name', 'like', "%{$word}%")
                ->orWhere('last_name', 'like', "%{$word}%"));
        }
    }

    /**
     * "DELA CRUZ, JUAN P. · 00101234567 · Barbaza" — how the member is shown in the transaction lookup.
     */
    public function lookupLabel(): string
    {
        return collect([$this->account_name ?? "{$this->last_name}, {$this->first_name}", $this->account_no, $this->branch?->name])
            ->filter()
            ->join(' · ');
    }

    /**
     * The transaction form fields this member fills in.
     *
     * @return array{account_no: string, last_name: string, first_name: string, middle_name: ?string, branch_id: ?int, birthdate: ?string, contact_number: ?string, savings_balance: ?string}
     */
    public function transactionDetails(): array
    {
        return [
            'account_no' => $this->account_no,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'branch_id' => $this->branch_id,
            'birthdate' => $this->birthdate?->toDateString(),
            'contact_number' => $this->contact_number,
            'savings_balance' => $this->savings_balance,
        ];
    }
}
