<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\Policy\PolicySettings;
use Database\Factories\BmpcMemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member of Barbaza MPC, from the coop's own member list — the people who may join COLISAP.
 * Separate from Member (a COLISAP participant): a new or active COLISAP transaction is filled in from here.
 */
class BmpcMember extends Model
{
    /** @use HasFactory<BmpcMemberFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'cif_key',
        'savings_no',
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
        'last_transaction_date',
        'segment',
        'category',
        'status',
        'remarks',
        'import_batch_id',
        'imported_by',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'savings_balance' => 'decimal:2',
        'last_transaction_date' => 'date',
    ];

    /**
     * The different values a column kept as the coop's file writes it (status, remarks) has, for its filter.
     *
     * @return array<string, string>
     */
    public static function distinctValues(string $column): array
    {
        return static::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->limit(100)
            ->pluck($column, $column)
            ->all();
    }

    /**
     * Age in whole years today, worked out from the birthdate.
     */
    public function age(): ?int
    {
        return $this->birthdate?->age;
    }

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
                ->where('cif_key', 'like', "%{$word}%")
                ->orWhere('savings_no', 'like', "%{$word}%")
                ->orWhere('account_name', 'like', "%{$word}%")
                ->orWhere('first_name', 'like', "%{$word}%")
                ->orWhere('middle_name', 'like', "%{$word}%")
                ->orWhere('last_name', 'like', "%{$word}%"));
        }
    }

    /**
     * "DELA CRUZ, JUAN P. · CIF 0000006392 · Barbaza" — how the member is shown in the transaction lookup.
     */
    public function lookupLabel(): string
    {
        return collect([$this->account_name ?? "{$this->last_name}, {$this->first_name}", $this->cif_key ? "CIF {$this->cif_key}" : null, $this->branch?->name])
            ->filter()
            ->join(' · ');
    }

    /**
     * The transaction form fields this member fills in. The COLISAP Savings Number is the member's savings number.
     *
     * @return array{account_no: ?string, cif_key: ?string, last_name: ?string, first_name: ?string, middle_name: ?string, branch_id: ?int, birthdate: ?string, sex: ?string, contact_number: ?string, address: ?string, segment: ?string, savings_balance: ?string}
     */
    public function transactionDetails(): array
    {
        return [
            'account_no' => $this->savings_no,
            'cif_key' => $this->cif_key,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'branch_id' => $this->branch_id,
            'birthdate' => $this->birthdate?->toDateString(),
            'sex' => $this->sex,
            'contact_number' => $this->contact_number,
            'address' => $this->address,
            'segment' => $this->segment,
            'savings_balance' => $this->savings_balance,
        ];
    }

    /**
     * "Active" or "Dormant" from the last transaction date and the dormancy period in Policy Settings;
     * null when the list has no transaction date.
     */
    public function accountStatus(): ?string
    {
        if ($this->last_transaction_date === null) {
            return null;
        }

        $dormantBefore = Carbon::today()->subMonthsNoOverflow(app(PolicySettings::class)->int('dormancy_months'));

        return $this->last_transaction_date->lt($dormantBefore) ? 'Dormant' : 'Active';
    }
}
