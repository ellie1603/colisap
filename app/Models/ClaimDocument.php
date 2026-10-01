<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ClaimDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'claim_id',
        'document_type',
        'file_path',
        'original_filename',
        'uploaded_by',
    ];

    /**
     * Remove the stored file when its document record is deleted.
     */
    protected static function booted(): void
    {
        static::deleted(fn (ClaimDocument $document) => Storage::disk('local')->delete($document->file_path));
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
