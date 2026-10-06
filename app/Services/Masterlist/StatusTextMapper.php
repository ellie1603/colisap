<?php

namespace App\Services\Masterlist;

use Illuminate\Support\Str;

/**
 * Interprets free-text masterlist status/remarks values.
 *  - map(): deceased / withdrawn (incl. branch transfers) / terminated (everything else is computed by the status engine)
 *  - dormancyFlag(): the savings system's dormant-txn / dormant-bal flags
 */
class StatusTextMapper
{
    /**
     * @var list<string>
     */
    private const DECEASED_EXACT = ['d', 'dec', 'dcd'];

    /**
     * @var list<string>
     */
    private const DECEASED_KEYWORDS = ['deceased', 'dead', 'died', 'expired', 'death'];

    /**
     * @var list<string>
     */
    private const WITHDRAWN_KEYWORDS = ['closed', 'closing', 'c-off', 'c off', 'coff', 'cut-off', 'cutoff', 'cut off', 'withdrawn', 'withdraw', 'w.draw', 'w/draw', 'wdraw', 'resigned'];

    /**
     * A member who moved branches keeps going under the new branch's account; the old account is closed.
     * Covers the masterlist spellings "Transferred to", "Transfererd to", "transfer to".
     *
     * @var list<string>
     */
    private const TRANSFER_KEYWORDS = ['transfer', 'transfered', 'transfererd'];

    /**
     * @var list<string>
     */
    private const TERMINATED_KEYWORDS = ['deactivated', 'deactivate', 'terminated', 'terminate'];

    /**
     * @return 'deceased'|'withdrawn'|'terminated'|null
     */
    public function map(mixed $text): ?string
    {
        $normalized = $this->normalize($text);

        if ($normalized === '' || $this->dormancyFlag($text) !== null) {
            return null;
        }

        if (in_array($normalized, self::DECEASED_EXACT, true) || Str::contains($normalized, self::DECEASED_KEYWORDS)) {
            return 'deceased';
        }

        if (Str::contains($normalized, self::TERMINATED_KEYWORDS)) {
            return 'terminated';
        }

        if (Str::contains($normalized, self::WITHDRAWN_KEYWORDS) || $this->isTransfer($text)) {
            return 'withdrawn';
        }

        return null;
    }

    public function isTransfer(mixed $text): bool
    {
        return Str::contains($this->normalize($text), self::TRANSFER_KEYWORDS);
    }

    /**
     * @return 'dormant-txn'|'dormant-bal'|null
     */
    public function dormancyFlag(mixed $text): ?string
    {
        $normalized = str_replace([' ', '_'], '-', $this->normalize($text));

        return match (true) {
            str_starts_with($normalized, 'dormant-t') || $normalized === 'dormant' => 'dormant-txn',
            str_starts_with($normalized, 'dormant-b') => 'dormant-bal',
            default => null,
        };
    }

    private function normalize(mixed $text): string
    {
        if (! is_string($text)) {
            return '';
        }

        return rtrim(Str::lower(trim(preg_replace('/\s+/u', ' ', $text) ?? '')), '.');
    }
}
