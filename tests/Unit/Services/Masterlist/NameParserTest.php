<?php

namespace Tests\Unit\Services\Masterlist;

use App\Services\Masterlist\NameParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameParserTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array{first_name: string, middle_name: ?string, last_name: string, suffix: ?string}}>
     */
    public static function names(): array
    {
        return [
            'last-first with middle initial' => ['DELA CRUZ, JUAN A.', ['first_name' => 'Juan', 'middle_name' => 'A.', 'last_name' => 'Dela Cruz', 'suffix' => null]],
            'last-first with suffix and bare initial' => ['SANTOS, PEDRO JR. B', ['first_name' => 'Pedro', 'middle_name' => 'B.', 'last_name' => 'Santos', 'suffix' => 'Jr.']],
            'last-first with full middle name' => ['Reyes, Maria Clara Gonzaga', ['first_name' => 'Maria Clara', 'middle_name' => 'Gonzaga', 'last_name' => 'Reyes', 'suffix' => null]],
            'last-first with two-word first name only' => ['REYES, MA. LUISA', ['first_name' => 'Ma. Luisa', 'middle_name' => null, 'last_name' => 'Reyes', 'suffix' => null]],
            'first-last with middle initial and suffix' => ['Jose P. Rizal III', ['first_name' => 'Jose', 'middle_name' => 'P.', 'last_name' => 'Rizal', 'suffix' => 'III']],
            'first-last with surname particle' => ['Ana Delos Santos', ['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Delos Santos', 'suffix' => null]],
            'extra whitespace' => ['  BAUTISTA ,   ROSA   M. ', ['first_name' => 'Rosa', 'middle_name' => 'M.', 'last_name' => 'Bautista', 'suffix' => null]],
            'masterlist account marker' => ['DELA CRUZ, DOMINGA (2)', ['first_name' => 'Dominga', 'middle_name' => null, 'last_name' => 'Dela Cruz', 'suffix' => null]],
            'stray trailing period' => ['TAL, HENRY .', ['first_name' => 'Henry', 'middle_name' => null, 'last_name' => 'Tal', 'suffix' => null]],
        ];
    }

    /**
     * @param  array{first_name: string, middle_name: ?string, last_name: string, suffix: ?string}  $expected
     */
    #[DataProvider('names')]
    public function test_splits_masterlist_name_into_parts(string $fullName, array $expected): void
    {
        $this->assertSame($expected, (new NameParser)->parse($fullName));
    }

    public function test_single_word_name_has_no_first_name(): void
    {
        $this->assertSame('', (new NameParser)->parse('CHER')['first_name']);
    }

    public function test_splits_suffix_from_standalone_name_column(): void
    {
        $this->assertSame(['Juan', 'Sr.'], (new NameParser)->splitSuffix('JUAN SR'));
    }
}
