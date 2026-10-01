<?php

namespace App\Services\Masterlist;

use Illuminate\Support\Str;

/**
 * Splits masterlist names into their parts. Handles both "LAST, FIRST MIDDLE SUFFIX"
 * and "First Middle Last Suffix" layouts, plus Filipino surname particles (Dela, De Los, Sta., ...).
 */
class NameParser
{
    /**
     * @var array<string, string>
     */
    private const SUFFIXES = [
        'jr' => 'Jr.',
        'sr' => 'Sr.',
        'ii' => 'II',
        'iii' => 'III',
        'iv' => 'IV',
        'v' => 'V',
    ];

    /**
     * @var list<string>
     */
    private const SURNAME_PARTICLES = ['de', 'dela', 'del', 'delos', 'della', 'di', 'la', 'las', 'los', 'san', 'sta', 'sto', 'santa', 'santo', 'van', 'von'];

    /**
     * @return array{first_name: string, middle_name: ?string, last_name: string, suffix: ?string}
     */
    public function parse(string $fullName): array
    {
        $fullName = $this->clean($fullName);

        if (str_contains($fullName, ',')) {
            [$lastName, $rest] = array_map('trim', explode(',', $fullName, 2));
            [$tokens, $suffix] = $this->extractSuffix($this->tokens(str_replace(',', ' ', $rest)));
            [$lastTokens, $lastSuffix] = $this->extractSuffix($this->tokens($lastName));
            $suffix ??= $lastSuffix;

            $middleName = null;

            if (count($tokens) >= 2 && ($this->isInitial(end($tokens)) || count($tokens) >= 3)) {
                $middleName = array_pop($tokens);
            }

            return $this->result(implode(' ', $tokens), $middleName, implode(' ', $lastTokens), $suffix);
        }

        [$tokens, $suffix] = $this->extractSuffix($this->tokens($fullName));

        if (count($tokens) <= 1) {
            return $this->result('', null, $tokens[0] ?? '', $suffix);
        }

        foreach ($tokens as $index => $token) {
            if ($index > 0 && $index < count($tokens) - 1 && $this->isInitial($token)) {
                return $this->result(
                    implode(' ', array_slice($tokens, 0, $index)),
                    $token,
                    implode(' ', array_slice($tokens, $index + 1)),
                    $suffix,
                );
            }
        }

        $lastNameStart = count($tokens) - 1;

        while ($lastNameStart > 1 && $this->isParticle($tokens[$lastNameStart - 1])) {
            $lastNameStart--;
        }

        return $this->result(
            implode(' ', array_slice($tokens, 0, $lastNameStart)),
            null,
            implode(' ', array_slice($tokens, $lastNameStart)),
            $suffix,
        );
    }

    /**
     * Normalize a single name part coming from its own column (e.g. "JUAN JR." → first "Juan", suffix "Jr.").
     *
     * @return array{0: string, 1: ?string}
     */
    public function splitSuffix(string $value): array
    {
        [$tokens, $suffix] = $this->extractSuffix($this->tokens($this->clean($value)));

        return [$this->titleCase(implode(' ', $tokens)), $suffix];
    }

    public function titleCase(?string $value): ?string
    {
        $value = $this->clean((string) $value);

        if ($value === '') {
            return null;
        }

        return Str::title(Str::lower($value));
    }

    public function normalizeSuffix(?string $value): ?string
    {
        $key = Str::lower(trim((string) $value, " .\t\n\r"));

        return self::SUFFIXES[$key] ?? ($key === '' ? null : $this->titleCase($value));
    }

    /**
     * Collapse whitespace and drop masterlist artifacts: "(2)" account markers and stray periods.
     */
    private function clean(string $value): string
    {
        $value = preg_replace('/\(\s*\d+\s*\)/u', ' ', $value) ?? $value;
        $value = preg_replace('/(^|\s)[.\-]+(?=\s|$)/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '', " \t\n\r\0\x0B,");
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        return array_values(array_filter(explode(' ', $this->clean($value)), fn (string $token) => $token !== ''));
    }

    /**
     * @param  list<string>  $tokens
     * @return array{0: list<string>, 1: ?string}
     */
    private function extractSuffix(array $tokens): array
    {
        $suffix = null;

        $tokens = array_values(array_filter($tokens, function (string $token) use (&$suffix) {
            $key = Str::lower(rtrim($token, '.'));

            if ($suffix === null && isset(self::SUFFIXES[$key])) {
                $suffix = self::SUFFIXES[$key];

                return false;
            }

            return true;
        }));

        return [$tokens, $suffix];
    }

    private function isInitial(string $token): bool
    {
        return (bool) preg_match('/^\p{L}{1,2}\.?$/u', $token) && ! $this->isParticle($token);
    }

    private function isParticle(string $token): bool
    {
        return in_array(Str::lower(rtrim($token, '.')), self::SURNAME_PARTICLES, true);
    }

    /**
     * @return array{first_name: string, middle_name: ?string, last_name: string, suffix: ?string}
     */
    private function result(string $firstName, ?string $middleName, string $lastName, ?string $suffix): array
    {
        return [
            'first_name' => (string) $this->titleCase($firstName),
            'middle_name' => $this->formatMiddleName($middleName),
            'last_name' => (string) $this->titleCase($lastName),
            'suffix' => $suffix,
        ];
    }

    private function formatMiddleName(?string $middleName): ?string
    {
        $middleName = $this->titleCase($middleName);

        if ($middleName !== null && mb_strlen($middleName) === 1) {
            return $middleName.'.';
        }

        return $middleName;
    }
}
