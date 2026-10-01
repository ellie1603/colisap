<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'importing' => 'Importing',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ];

    protected $fillable = [
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

    protected $casts = [
        'as_of_date' => 'date',
        'sheets' => 'array',
        'completed_at' => 'datetime',
    ];

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'failed' => 'danger',
            'importing' => 'warning',
            'cancelled' => 'gray',
            default => 'info',
        };
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

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
