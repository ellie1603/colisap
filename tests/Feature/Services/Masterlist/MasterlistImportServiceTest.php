<?php

namespace Tests\Feature\Services\Masterlist;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Member;
use App\Models\SavingsTransaction;
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

    public function test_messy_natcco_masterlist_with_unsaved_formulas_side_tables_and_remarks_is_organized(): void
    {
        // Mirrors the real NATCCO export: Segmentation / Savings columns are VLOOKUP formulas saved without results,
        // the real values live in side tables (M:O segmentation, Q:S balances, U:W status), and one side table
        // carries an extra leading zero on its account numbers.
        $row = fn (array $main, array $side = []) => array_replace(array_fill(0, 23, ''), $main, $side);
        $vlookups = fn (int $r) => [3 => "=VLOOKUP(B{$r},M:O,3,FALSE)", 7 => '=', 8 => '=', 9 => "=VLOOKUP(B{$r},Q:S,3,FALSE)"];

        $batch = $this->stage(['Janiuay' => $this->sheet(
            $row([1 => '01001000011', 2 => 'JUAN, PEDRO A.', 4 => 'COLISAP40000', 5 => '2016-09-02'] + $vlookups(5), [12 => 'SEGMENTATION', 16 => 'SAVINGS BALANCES', 20 => 'STATUS']),
            $row([0 => 'Transferred to Sara', 1 => '01001000012', 2 => 'SANTOS, MARIA', 4 => 'COLISAP60000', 5 => '2015-06-09'] + $vlookups(6),
                [12 => '001001000011', 13 => 'JUAN, PEDRO A', 14 => 'G', 16 => '01001000011', 17 => 'JUAN, PEDRO A.', 18 => 1234.5, 20 => '01001000014', 21 => 'REYES, ANA', 22 => 'w.draw']),
            $row([0 => 'deactivated 7-20-26', 1 => '01001000013', 2 => 'CRUZ, JOSE', 4 => 'COLISAP40000', 5 => '2014-07-31'] + $vlookups(7),
                [16 => '01001000012', 17 => 'SANTOS, MARIA', 18 => 2500]),
            $row([1 => '01001000014', 2 => 'REYES, ANA', 4 => 'COLISAP40000', 5 => '6/ 6/2025'] + $vlookups(8)),
        )]);

        $this->assertSame([4, 0], [$batch->new_rows, $batch->invalid_rows]);
        $this->import($batch);

        $members = Member::orderBy('account_no')->get()->keyBy('account_no');

        $this->assertSame(['G', '1234.50'], [$members['01001000011']->segment, $members['01001000011']->savings_balance], 'Side-table values win over unsaved formulas, even with an extra leading zero.');
        $this->assertSame('2500.00', $members['01001000012']->savings_balance, 'The text "=VLOOKUP(B6,…)" is never read as a balance.');
        $this->assertSame(['withdrawn', 'terminated', 'withdrawn'], [$members['01001000012']->status, $members['01001000013']->status, $members['01001000014']->status]);
        $this->assertStringContainsString('Branch transfer', $members['01001000012']->withdrawal_reason);
        $this->assertSame('2025-06-06', $members['01001000014']->approval_date->toDateString());
        $this->assertSame(['60000', 'Janiuay'], [$members['01001000012']->category, $members['01001000012']->branch->name]);
    }

    public function test_deleting_an_import_removes_the_members_it_added_and_takes_back_its_balance_changes(): void
    {
        $existing = Member::factory()->create(['account_no' => '00101959720', 'savings_balance' => 500]);
        $untouched = Member::factory()->create(['account_no' => '00109999999']);

        $batch = $this->import($this->stage(['Barbaza' => $this->sheet(
            ['', '00101959720', 'DELA CRUZ, JUAN A.', 'G', 'COLISAP40000', '6/3/1998', '', 10338, 'qualified', 800, 'for upload'],
            ['', '00101959721', 'SANTOS, MARIA B.', 'D', 'COLISAP40000', '6/3/1999', '', 10, 'qualified', 900, 'for upload'],
        )]));

        $added = Member::where('account_no', '00101959721')->sole();
        $this->assertSame('800.00', $existing->fresh()->savings_balance);
        Storage::disk('local')->assertExists($batch->file_path);

        $service = app(MasterlistImportService::class);
        $this->assertSame(['members' => 1, 'adjustments' => 1], $service->importedDataSummary($batch));
        $this->assertSame(['members' => 1, 'adjustments' => 1], $service->deleteImportedData($batch));

        $this->assertDatabaseMissing('members', ['id' => $added->id]);
        $this->assertSame(0, Application::where('member_id', $added->id)->count() + SavingsTransaction::where('member_id', $added->id)->count());
        $this->assertSame('500.00', $existing->fresh()->savings_balance, 'The balance change made by this file is taken back.');
        $this->assertNotNull($untouched->fresh());
        $this->assertSame([0, 0], [ImportBatch::count(), ImportRow::count()]);
        Storage::disk('local')->assertMissing($batch->file_path);
        $this->assertTrue(AuditLog::where('action', 'imported_data_deleted')->exists());
    }

    public function test_bulk_import_keeps_its_trail_without_one_audit_entry_per_record(): void
    {
        $batch = $this->import($this->stage(['Barbaza' => $this->sheet(
            ['', '00101959720', 'DELA CRUZ, JUAN A.', 'G', 'COLISAP40000', '6/3/1998', '', 10338, 'qualified', 800, 'for upload'],
            ['', '00101959721', 'SANTOS, MARIA B.', 'D', 'COLISAP40000', '6/3/1999', '', 10, 'qualified', 900, 'for upload'],
        )]));

        $member = Member::where('account_no', '00101959720')->sole();

        $this->assertSame(0, AuditLog::whereIn('auditable_type', [Member::class, SavingsTransaction::class, Application::class])->count());
        $this->assertSame([$batch->id, 'Barbaza', 5], [$member->import_batch_id, $member->source_sheet, $member->source_row], 'Each member points back to its source row.');
        $this->assertTrue($member->histories()->where('source', 'import')->exists(), 'Status changes are still recorded in the member history.');
        $this->assertFalse(AuditLog::recordingPaused(), 'Auditing resumes after the import chunk.');
    }
}
