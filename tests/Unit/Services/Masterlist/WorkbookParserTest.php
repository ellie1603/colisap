<?php

namespace Tests\Unit\Services\Masterlist;

use App\Services\Masterlist\NameParser;
use App\Services\Masterlist\StatusTextMapper;
use App\Services\Masterlist\WorkbookParser;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WorkbookParserTest extends TestCase
{
    /**
     * Header row as it appears in the BMPC COLISAP masterlist.
     */
    private const MASTERLIST_HEADER = ['Remarks', 'Acct. Number', 'Account Name', 'Segmentation', 'Category 30K/50K', 'NEW Date of Approval', 'UPGRADING Date of Application', '09/22/26', 'NEW # of Days', 'UPGRADING # of Days', 'NEW STATUS Qualified', 'UPGRADING', 'Savings Balance', 'x'];

    private function parser(): WorkbookParser
    {
        return new WorkbookParser(new NameParser, new StatusTextMapper);
    }

    public function test_maps_masterlist_columns_and_ignores_computed_ones(): void
    {
        [$headerIndex, $columns] = $this->parser()->detectHeader([
            ['BARBAZA MULTI-PURPOSE COOPERATIVE'],
            ['Barbaza Branch'],
            [],
            ['Schedules of COLISAP Members'],
            self::MASTERLIST_HEADER,
        ]);

        $this->assertSame(4, $headerIndex);
        $this->assertSame([
            'remarks' => 0,
            'account_no' => 1,
            'full_name' => 2,
            'segment' => 3,
            'category' => 4,
            'approval_date' => 5,
            'upgrade_requested_at' => 6,
            'savings_balance' => 12,
        ], $columns);
    }

    public function test_normalizes_a_masterlist_row_keeping_leading_zeros(): void
    {
        [, $columns] = $this->parser()->detectHeader([self::MASTERLIST_HEADER]);

        $row = $this->parser()->normalizeRow(
            ['', '00101959720', 'DELA CRUZ, DOMINGA (2)', 'G', 'COLISAP40000', new DateTimeImmutable('1998-06-03'), '6/20/2024', '09/22/26', 10338, 46287, 'qualified', 'qualified', 57030.04, 'for upload'],
            $columns,
        );

        $this->assertSame([], $row['errors']);
        $this->assertSame([
            '00101959720', 'DELA CRUZ, DOMINGA (2)', 'Dominga', 'Dela Cruz', 'G', '40000', '1998-06-03', '2024-06-20', 57030.04, null,
        ], [
            $row['data']['account_no'], $row['data']['account_name'], $row['data']['first_name'], $row['data']['last_name'],
            $row['data']['segment'], $row['data']['category'], $row['data']['approval_date'], $row['data']['upgrade_requested_at'],
            $row['data']['savings_balance'], $row['data']['terminal_status'],
        ]);
    }

    public function test_excel_errors_are_never_treated_as_data(): void
    {
        [, $columns] = $this->parser()->detectHeader([self::MASTERLIST_HEADER]);

        $row = $this->parser()->normalizeRow(
            ['', '00101000031', 'JORDAN, PRINCESS T.', '#N/A', 'COLISAP40000', '1/2/1999', null, null, null, null, '#N/A', null, '#######', '#N/A'],
            $columns,
        );

        $this->assertNull($row['data']['segment']);
        $this->assertNull($row['data']['savings_balance']);
        $this->assertArrayNotHasKey('segment', $row['raw']);
        $this->assertContains('No segmentation — left Unassigned', $row['warnings']);
    }

    /**
     * @return array<string, array{0: array<int, mixed>, 1: string}>
     */
    public static function invalidRows(): array
    {
        return [
            'blank Acct. Number' => [['', '', 'SANTOS, MARIA', 'S', 'COLISAP40000', '1/2/2020'], 'Missing Acct. Number'],
            'invalid category' => [['', '00101000049', 'SANTOS, MARIA', 'S', 'COLISAP30000', '1/2/2020'], 'Invalid category "COLISAP30000"'],
            'invalid approval date' => [['', '00101000049', 'SANTOS, MARIA', 'S', 'COLISAP40000', 'sometime'], 'Invalid approval date (new date) "sometime"'],
            'negative balance' => [['', '00101000049', 'SANTOS, MARIA', 'S', 'COLISAP40000', '1/2/2020', null, null, null, null, null, null, -5], 'Negative savings balance -5.00'],
        ];
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    #[DataProvider('invalidRows')]
    public function test_flags_invalid_rows(array $cells, string $expectedError): void
    {
        [, $columns] = $this->parser()->detectHeader([self::MASTERLIST_HEADER]);

        $this->assertContains($expectedError, $this->parser()->normalizeRow($cells, $columns)['errors']);
    }

    public function test_remarks_mark_deceased_and_withdrawn(): void
    {
        [, $columns] = $this->parser()->detectHeader([self::MASTERLIST_HEADER]);

        $deceased = $this->parser()->normalizeRow(['D', '00101000065', 'ESPARAGOZA, LORREN N.', 'R', 'COLISAP40000', '1/2/2020'], $columns);
        $withdrawn = $this->parser()->normalizeRow(['closed c-off', '00101000073', 'OMAPAS, JOSIE R.', 'R', 'COLISAP40000', '1/2/2020'], $columns);

        $this->assertSame(['deceased', 'withdrawn'], [$deceased['data']['terminal_status'], $withdrawn['data']['terminal_status']]);
    }

    public function test_reads_side_lists_of_dormancy_flags_segments_and_balances(): void
    {
        $entries = $this->parser()->sideListEntries([
            null, '00101990510 ESPARAGOZA, JOHN RAY', 'dormant-txn', null,
            '00101930870', 'FRANCISCO, ANTONIETA', 'deceased', null,
            '00101966790', 'PEREYRA, AIDA', 'D', null,
            '00101000031 JORDAN, PRINCESS TIOSAN', 7858.51,
        ], []);

        $this->assertSame([
            ['00101990510', 'flag', 'dormant-txn'],
            ['00101930870', 'terminal', 'deceased'],
            ['00101966790', 'segment', 'D'],
            ['00101000031', 'balance', 7858.51],
        ], array_map(fn (array $entry) => [$entry['account_no'], $entry['kind'], $entry['value']], $entries));
    }

    public function test_numeric_account_number_is_reported_for_padding(): void
    {
        [, $columns] = $this->parser()->detectHeader([self::MASTERLIST_HEADER]);

        $row = $this->parser()->normalizeRow(['', 101959720, 'DELA CRUZ, JUAN', 'R', 'COLISAP40000', '1/2/2020'], $columns);

        $this->assertTrue($row['numeric_account']);
        $this->assertSame('101959720', $row['data']['account_no']);
    }

    public function test_total_and_blank_rows_are_skipped(): void
    {
        [, $columns] = $this->parser()->detectHeader([self::MASTERLIST_HEADER]);

        $this->assertTrue($this->parser()->normalizeRow(['', '', 'TOTAL', '', '', '', null, null, null, null, null, null, 123456.78], $columns)['blank']);
        $this->assertTrue($this->parser()->normalizeRow(['', '', '#N/A', '', '', ''], $columns)['blank']);
    }

    /**
     * @return array<string, array{0: mixed, 1: ?string}>
     */
    public static function categories(): array
    {
        return [
            'masterlist code' => ['COLISAP40000', '40000'],
            'upgraded code' => ['COLISAP60000', '60000'],
            'formatted amount' => ['P60,000.00', '60000'],
            'short' => ['40K', '40000'],
            'number' => [40000.0, '40000'],
            'misleading 30K label value' => ['30K', null],
        ];
    }

    #[DataProvider('categories')]
    public function test_parses_benefit_category(mixed $value, ?string $expected): void
    {
        $this->assertSame($expected, $this->parser()->parseCategory($value));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function segments(): array
    {
        return [
            'D' => ['D', 'D'],
            'gold word' => ['Gold', 'G'],
            'lowercase s' => ['s', 'S'],
            'regular' => ['R', 'R'],
            'excel error' => ['#N/A', null],
            'unknown' => ['X', null],
        ];
    }

    #[DataProvider('segments')]
    public function test_parses_segmentation_separately_from_category(?string $value, ?string $expected): void
    {
        $this->assertSame($expected, $this->parser()->parseSegment($value === '#N/A' ? $this->parser()->cellToString($value) : $value));
    }
}
