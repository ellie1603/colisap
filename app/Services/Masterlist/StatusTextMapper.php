<?php

namespace App\Services\Masterlist;

use Illuminate\Support\Str;

/**
 * Interprets free-text masterlist status/remarks values.
 *  - terminal(): deceased / withdrawn (everything else is computed by the status engine)
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
    private const WITHDRAWN_KEYWORDS = ['closed', 'c-off', 'c off', 'coff', 'cut-off', 'cutoff', 'cut off', 'withdrawn', 'withdraw', 'resigned'];

    /**
     * @return 'deceased'|'withdrawn'|null
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

        if (Str::contains($normalized, self::WITHDRAWN_KEYWORDS)) {
            return 'withdrawn';
        }

        return null;
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
