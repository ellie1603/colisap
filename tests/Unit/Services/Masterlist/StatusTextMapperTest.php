<?php

namespace Tests\Unit\Services\Masterlist;

use App\Services\Masterlist\StatusTextMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StatusTextMapperTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: ?string}>
     */
    public static function terminalTexts(): array
    {
        return [
            'D code' => ['D', 'deceased'],
            'lowercase d with period' => [' d. ', 'deceased'],
            'deceased word' => ['deceased', 'deceased'],
            'closed c-off' => ['closed c-off', 'withdrawn'],
            'withdrawn word' => ['Withdrawn', 'withdrawn'],
            'qualified is computed, not a status' => ['qualified', null],
            'dormant flag is not terminal' => ['dormant-txn', null],
            'blank' => ['   ', null],
            'non-string' => [123, null],
        ];
    }

    #[DataProvider('terminalTexts')]
    public function test_maps_terminal_statuses_only(mixed $text, ?string $expected): void
    {
        $this->assertSame($expected, (new StatusTextMapper)->map($text));
    }

    /**
     * @return array<string, array{0: mixed, 1: ?string}>
     */
    public static function dormancyTexts(): array
    {
        return [
            'transaction dormancy' => ['dormant-txn', 'dormant-txn'],
            'balance dormancy' => ['DORMANT-BAL', 'dormant-bal'],
            'plain dormant' => ['dormant', 'dormant-txn'],
            'active' => ['active', null],
        ];
    }

    #[DataProvider('dormancyTexts')]
    public function test_reads_savings_dormancy_flags(mixed $text, ?string $expected): void
    {
        $this->assertSame($expected, (new StatusTextMapper)->dormancyFlag($text));
    }
}
