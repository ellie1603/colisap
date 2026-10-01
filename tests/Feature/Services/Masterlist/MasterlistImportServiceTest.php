<?php

namespace Tests\Feature\Services\Masterlist;

use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\Member;
use App\Services\Masterlist\MasterlistImportService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesMasterlistWorkbooks;
use Tests\TestCase;

class MasterlistImportServiceTest extends TestCase
{
    use CreatesMasterlistWorkbooks, LazilyRefreshDatabase;

    private const HEADER = ['Remarks', 'Acct. Number', 'Account Name', 'Segmentation', 'Category 30K/50K', 'NEW Date of Approval', 'UPGRADING Date of Application', 'NEW # of Days', 'NEW STATUS Qualified', 'Savings Balance', 'x'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-22 08:00:00');
        Storage::fake('local');
        Storage::disk('local')->makeDirectory(MasterlistImportService::DIRECTORY);
    }

    /**
     * @param  array<string, list<list<mixed>>>  $sheets
     */
    private function stage(array $sheets): ImportBatch
    {
        $path = MasterlistImportService::DIRECTORY.'/masterlist.xlsx';
        $this->writeMasterlistWorkbook(Storage::disk('local')->path($path), $sheets);

        $service = app(MasterlistImportService::class);
        $batch = $service->createBatch($path, 'masterlist.xlsx', '2026-09-22');

        foreach ($service->includedSheetPositions($batch) as $position) {
            $service->stageSheet($batch->refresh(), $position);
        }

        return $service->finalize($batch->refresh());
    }

    private function import(ImportBatch $batch, int $chunk = 200): ImportBatch
    {
        $service = app(MasterlistImportService::class);

        while ($service->importChunk($batch->refresh(), $chunk) > 0) {
            // keep importing chunk by chunk, exactly like the browser does
        }

        return $batch->refresh();
    }

    /**
     * @return list<list<mixed>>
     */
    private function sheet(array ...$rows): array
    {
        return [['BARBAZA MULTI-PURPOSE COOPERATIVE'], ['Schedules of COLISAP'], [], self::HEADER, ...$rows];
    }

    public function test_detects_branch_sheets_and_skips_sheets_without_a_masterlist(): void
    {
        $batch = $this->stage([
            'Barbaza' => $this->sheet(['', '00101959720', 'DELA CRUZ, JUAN A.', 'G', 'COLISAP40000', '6/3/1998', '', 10338, 'qualified', 57030.04, 'for upload']),
            'BAROTAC VIEJO' => $this->sheet(['', '00101959721', 'SANTOS, MARIA B.', 'D', 'COLISAP40000', '6/3/2026', '', 10, 'qualified', 800, 'for upload']),
            'Summary' => [['Branch', 'Count'], ['Barbaza', 1]],
        ]);

        $this->assertSame([['Barbaza', 'Barbaza', true], ['BAROTAC VIEJO', 'Barotac Viejo', true], ['Summary', null, false]],
            array_map(fn (array $sheet) => [$sheet['name'], $sheet['branch_name'], $sheet['include']], $batch->sheets));
        $this->assertSame([2, 2], [$batch->total_rows, $batch->new_rows]);
    }

    public function test_imports_members_with_identity_branch_segment_category_balance_and_computed_status(): void
    {
        $batch = $this->import($this->stage([
            'Barbaza' => $this->sheet(
                ['', '00101959720', 'DELA CRUZ, JUAN A.', 'G', 'COLISAP40000', '6/3/1998', '6/20/2024', 10338, 'qualified', 57030.04, 'for upload'],
                ['', '00101959722', 'REYES, ANA', '#N/A', 'COLISAP60000', '8/1/2026', '', 52, 'disqualified', 2500, 'for upload'],
            ),
        ]));

        $juan = Member::where('account_no', '00101959720')->sole();
        $ana = Member::where('account_no', '00101959722')->sole();

        $this->assertSame(2, $batch->created_count);
        $this->assertSame(['Juan', 'Dela Cruz', 'Barbaza', 'G', '40000', 'active', '57030.04', '2024-06-20'], [
            $juan->first_name, $juan->last_name, $juan->branch->name, $juan->segment, $juan->category, $juan->status,
            $juan->savings_balance, $juan->category_upgrade_requested_at->toDateString(),
        ]);
        $this->assertSame([null, '60000', 'waiting'], [$ana->segment, $ana->category, $ana->status], '#N/A segment stays Unassigned; 52 days into the waiting period.');
        $this->assertSame('approved', $juan->applications()->sole()->status);
        $this->assertSame([$batch->id, 'Barbaza', 5], [$juan->import_batch_id, $juan->source_sheet, $juan->source_row]);
    }

    public function test_existing_accounts_are_updated_never_duplicated(): void
    {
        $existing = Member::factory()->create(['account_no' => '00101959720', 'last_name' => 'Old', 'savings_balance' => 100]);

        $batch = $this->import($this->stage([
            'Sibalom' => $this->sheet(['', '00101959720', 'DELA CRUZ, JUAN A.', 'S', 'COLISAP40000', '6/3/1998', '', 1, 'qualified', 900, '']),
        ]));

        $existing->refresh();
        $this->assertSame([0, 1, 1], [$batch->new_rows, $batch->update_rows, $batch->updated_count]);
        $this->assertSame(1, Member::count());
        $this->assertSame(['Dela Cruz', 'Sibalom', 'S', '900.00'], [$existing->last_name, $existing->branch->name, $existing->segment, $existing->savings_balance]);
        $this->assertSame('import_adjustment', $existing->savingsTransactions()->sole()->type);
    }

    public function test_duplicates_and_invalid_rows_are_skipped_and_reported(): void
    {
        $batch = $this->stage([
            'Barbaza' => $this->sheet(
                ['', '00101000001', 'ONE, FIRST', 'R', 'COLISAP40000', '1/1/2020', '', '', '', 1000, ''],
                ['', '', 'NOACCT, MISSING', 'R', 'COLISAP40000', '1/1/2020', '', '', '', 1000, ''],
                ['', '00101000002', 'BAD, CATEGORY', 'R', 'COLISAP30000', '1/1/2020', '', '', '', 1000, ''],
                ['', '00101000003', 'NODATE, NEWBIE', 'R', 'COLISAP40000', '', '', '', '', 1000, ''],
            ),
            'Culasi' => $this->sheet(['', '00101000001', 'ONE, FIRST', 'R', 'COLISAP40000', '1/1/2020', '', '', '', 1000, '']),
        ]);

        $this->assertSame(['total' => 5, 'new' => 1, 'duplicate' => 1, 'invalid' => 3], [
            'total' => $batch->total_rows, 'new' => $batch->new_rows, 'duplicate' => $batch->duplicate_rows, 'invalid' => $batch->invalid_rows,
        ]);

        ob_start();
        app(MasterlistImportService::class)->errorReport($batch)->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('Missing Acct. Number', $csv);
        $this->assertStringContainsString('Invalid category', $csv);
        $this->assertStringContainsString('New member has no approval (NEW) date', $csv);
        $this->assertStringContainsString('appears more than once', $csv);

        $this->import($batch);
        $this->assertSame(['00101000001'], Member::pluck('account_no')->all());
    }

    public function test_side_lists_flag_dormant_and_deceased_accounts(): void
    {
        $this->import($this->stage([
            'Kalibo' => [
                ...$this->sheet(
                    ['', '00101990510', 'ESPARAGOZA, JOHN RAY', 'R', 'COLISAP40000', '1/1/2020', '', '', '', 1000, '', '', '00101990510 ESPARAGOZA, JOHN RAY', 'dormant-txn'],
                    ['', '00101930870', 'FRANCISCO, ANTONIETA', 'R', 'COLISAP40000', '1/1/2020', '', '', '', 1000, '', '', '00101930870 FRANCISCO, ANTONIETA', 'deceased'],
                ),
            ],
        ]));

        $this->assertSame([
            '00101930870' => 'deceased',
            '00101990510' => 'dormant',
        ], Member::orderBy('account_no')->pluck('status', 'account_no')->all());
        $this->assertSame('dormant-txn', Member::where('account_no', '00101990510')->value('savings_account_status'));
    }

    public function test_reimporting_the_same_file_changes_nothing(): void
    {
        $sheets = ['Barbaza' => $this->sheet(['', '00101959720', 'DELA CRUZ, JUAN A.', 'G', 'COLISAP40000', '6/3/1998', '', '', '', 57030.04, ''])];

        $this->import($this->stage($sheets));
        $second = $this->import($this->stage($sheets));

        $this->assertSame([0, 0, 1], [$second->created_count, $second->updated_count, $second->unchanged_count]);
        $this->assertSame(1, Member::first()->savingsTransactions()->count());
    }

    public function test_large_masterlist_imports_completely_in_chunks(): void
    {
        $rows = [];

        for ($i = 1; $i <= 1200; $i++) {
            $rows[] = ['', sprintf('00102%06d', $i), "MEMBER{$i}, TEST", ['D', 'G', 'S', 'R'][$i % 4], 'COLISAP40000', '1/15/2020', '', '', '', 1000 + $i, ''];
        }

        $batch = $this->stage(['Molo' => $this->sheet(...$rows)]);
        $this->assertSame(1200, $batch->new_rows);

        $batch = $this->import($batch, chunk: 250);

        $this->assertSame(['completed', 1200, 1200], [$batch->status, $batch->created_count, Member::count()]);
        $this->assertSame('00102000007', Member::where('account_name', 'MEMBER7, TEST')->value('account_no'));
        $this->assertSame(1200, Member::where('branch_id', Branch::where('name', 'Molo')->value('id'))->where('status', 'active')->count());
    }
}
