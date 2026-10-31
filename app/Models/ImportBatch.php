<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * One masterlist upload: its sheet/branch/column mapping, staged rows and results.
 */
class ImportBatch extends Model
{
    use Auditable;

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'uploaded' => 'Uploaded',
        'analyzed' => 'Mapping',
        'staged' => 'Ready to import',
        'awaiting_approval' => 'Awaiting approval',
        'importing' => 'Importing',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'rejected' => 'Rejected',
    ];

    /**
     * What a file was imported into.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'members' => 'COLISAP masterlist',
        'bmpc_members' => 'BMPC members',
        'savings' => 'Savings balances',
    ];

    protected $fillable = [
        'type',
        'file_name',
        'file_path',
        'as_of_date',
        'status',
        'sheets',
        'total_rows',
        'new_rows',
        'update_rows',
        'duplicate_rows',
        'invalid_rows',
        'warning_rows',
        'processed_rows',
        'created_count',
        'updated_count',
        'unchanged_count',
        'error_message',
        'uploaded_by',
        'completed_at',
    ];

    protected $attributes = [
        'type' => 'members',
    ];

    protected $casts = [
        'as_of_date' => 'date',
        'sheets' => 'array',
        'completed_at' => 'datetime',
    ];

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'failed', 'rejected' => 'danger',
            'importing', 'awaiting_approval' => 'warning',
            'cancelled' => 'gray',
            default => 'info',
        };
    }

    /**
     * Staff limited to one branch see the imports that only touched that branch, plus their own unfinished uploads.
     *
     * @param  Builder<ImportBatch>  $query
     */
    public function scopeVisibleToCurrentUser(Builder $query): void
    {
        $branchId = Branch::restrictedId();

        if ($branchId === null) {
            return;
        }

        $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query
                ->whereHas('rows')
                ->whereDoesntHave('rows', fn (Builder $rows) => $rows->where(fn (Builder $rows) => $rows
                    ->where('branch_id', '<>', $branchId)
                    ->orWhereNull('branch_id'))))
            ->orWhere(fn (Builder $query) => $query
                ->whereDoesntHave('rows')
                ->where('uploaded_by', Auth::id())));
    }

    public function isBmpcMemberImport(): bool
    {
        return $this->type === 'bmpc_members';
    }

    public function isSavingsImport(): bool
    {
        return $this->type === 'savings';
    }

    /**
     * The COLISAP masterlist import, with its checking, preview and approval steps; the other uploads
     * (BMPC members, savings balances) are written straight away.
     */
    public function isMasterlistImport(): bool
    {
        return $this->type === 'members';
    }

    /**
     * Rows that will be written (new + updates).
     */
    public function importableCount(): int
    {
        return $this->new_rows + $this->update_rows;
    }

    public function progressPercent(): int
    {
        $total = $this->importableCount();

        return $total === 0 ? 100 : (int) min(100, floor($this->processed_rows / $total * 100));
    }

    /**
     * @return list<string>
     */
    public function branchNames(): array
    {
        return collect($this->sheets ?? [])
            ->filter(fn (array $sheet) => $sheet['include'] ?? false)
            ->map(fn (array $sheet) => $sheet['branch_name'] ?? $sheet['name'])
            ->values()
            ->all();
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    /**
     * BMPC members this upload added.
     */
    public function bmpcMembers(): HasMany
    {
        return $this->hasMany(BmpcMember::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
