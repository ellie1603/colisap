<?php

namespace App\Services\Masterlist;

use App\Models\BmpcMember;
use App\Models\Branch;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Reads the coop's Excel list of every Barbaza MPC member into BMPC Members, straight away (no approval).
 * Every sheet with an account number (or CIF Key) and a name column is read; all other columns are ignored.
 * Members already on the list are matched by account number and updated; a blank cell never erases a value.
 * Each upload is kept in Import History, and new members remember it so deleting the upload removes them again.
 */
class BmpcMemberImportService
{
    private const CHUNK = 500;

    /**
     * The details kept from the file, in table column order.
     *
     * @var list<string>
     */
    private const FIELDS = [
        'account_name', 'last_name', 'first_name', 'middle_name', 'suffix', 'branch_id', 'birthdate', 'sex', 'contact_number', 'address',
        'savings_balance', 'last_transaction_date', 'segment', 'category', 'remarks',
    ];

    public function __construct(private WorkbookParser $parser) {}

    /**
     * The whole file at once (a large workbook is imported sheet by sheet from the page instead, see importSheet()).
     *
     * @return array{added: int, updated: int, skipped: int, other_branch: int, sheets: int}
     */
    public function import(string $path, ?int $importBatchId = null): array
    {
        $summary = self::emptySummary();

        foreach ($this->sheets($path) as $sheet) {
            $this->importSheet($path, $sheet, $summary, $importBatchId);
        }

        return $summary;
    }

    /**
     * @return array{added: int, updated: int, skipped: int, other_branch: int, sheets: int}
     */
    public static function emptySummary(): array
    {
        return ['added' => 0, 'updated' => 0, 'skipped' => 0, 'other_branch' => 0, 'sheets' => 0];
    }

    /**
     * The sheets that hold a member list: an account number (or CIF Key) column and a name column.
     *
     * @return list<array{index: int, name: string, header_row: int, columns: array<string, int>, branch_id: ?int, branch_column: ?int, contact_columns: list<int>}>
     */
    public function sheets(string $path): array
    {
        $sheets = [];

        foreach ($this->parser->detectSheets($path) as $sheet) {
            $columns = $sheet['columns'];

            if ($sheet['header_row'] !== null && isset($columns['account_no']) && (isset($columns['full_name']) || isset($columns['last_name'], $columns['first_name']))) {
                // The coop list has "Classification" (MIGS / NON-MIGS) before "Segmentation Status" (Diamond, Gold,
                // Silver): the segmentation is the column that says so.
                foreach ($sheet['headers'] as $column => $header) {
                    if (str_contains(strtolower($header), 'segment')) {
                        $sheet['columns']['segment'] = $column;

                        break;
                    }
                }

                $sheets[] = [
                    ...array_intersect_key($sheet, array_flip(['index', 'name', 'header_row', 'columns', 'branch_id'])),
                    'branch_column' => $this->branchColumn($sheet['headers']),
                    'contact_columns' => $this->contactColumns($sheet['headers']),
                    'last_transaction_column' => $this->parser->lastTransactionColumn($sheet['headers']),
                ];
            }
        }

        return $sheets;
    }

    /**
     * A "Branch" / "Origin branch" column, when the list has one.
     *
     * @param  array<int, string>  $headers
     */
    private function branchColumn(array $headers): ?int
    {
        foreach ($headers as $column => $header) {
            if (in_array(preg_replace('/[^a-z]/', '', strtolower($header)), ['branch', 'branchname', 'originbranch', 'homebranch', 'memberbranch'], true)) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Every phone column, mobile ("Contact #", "Mobile no.") before landline-named ones ("Telephone #"), which
     * member lists often use for mobile numbers too.
     *
     * @param  array<int, string>  $headers
     * @return list<int>
     */
    private function contactColumns(array $headers): array
    {
        $columns = array_keys(array_filter($headers, fn (string $header) => $this->parser->matchHeader($header) === 'contact_number'));
        usort($columns, fn (int $a, int $b) => preg_match('/tel|phone/i', $headers[$a]) <=> preg_match('/tel|phone/i', $headers[$b]) ?: $a <=> $b);

        return $columns;
    }

    /**
     * Import one sheet found by sheets(), adding its counts to the summary.
     *
     * @param  array{index: int, name: string, header_row: int, columns: array<string, int>, branch_id: ?int, branch_column?: ?int, contact_columns?: list<int>}  $sheet
     * @param  array{added: int, updated: int, skipped: int, other_branch: int, sheets: int}  $summary
     * @param  ?int  $importBatchId  the Import History entry new members are recorded against
     */
    public function importSheet(string $path, array $sheet, array &$summary, ?int $importBatchId = null): void
    {
        $homeBranchId = Branch::restrictedId();
        $branchesByCode = Branch::query()->whereNotNull('code')->pluck('id', 'code')->all();
        $branchesByName = [];
        $summary['sheets']++;
        $pending = [];

        foreach ($this->parser->rows($path, $sheet['index'], $sheet['header_row']) as $cells) {
            $row = $this->parser->normalizeRow($cells, $sheet['columns'], allowNegativeBalance: true);

            if ($row['blank']) {
                continue;
            }

            $data = $row['data'];
            // A stray period typed after the CIF Key ("00400000SN8168.") is not part of it.
            $data['account_no'] = rtrim((string) $data['account_no'], '.') ?: null;

            if ($data['account_no'] === null || ! preg_match('/^[0-9A-Za-z\-]{4,30}$/', $data['account_no']) || $data['last_name'] === '' || $data['first_name'] === '') {
                $summary['skipped']++;

                continue;
            }

            foreach ($sheet['contact_columns'] ?? [] as $column) {
                if ($contactNumber = $this->parser->parseContactNumber($this->parser->cellToString($cells[$column] ?? null))) {
                    $data['contact_number'] = $contactNumber;

                    break;
                }
            }

            // The branch column when there is one, else the branch code in the account number's first three
            // digits, else the sheet's name.
            $branchName = isset($sheet['branch_column']) ? $this->parser->cellToString($cells[$sheet['branch_column']] ?? null) : '';
            $branchId = ($branchName !== '' ? $branchesByName[$branchName] ??= Branch::resolve($branchName)?->id : null)
                ?? $branchesByCode[substr($data['account_no'], 0, 3)]
                ?? $sheet['branch_id'];

            if ($homeBranchId !== null && $branchId !== null && $branchId !== $homeBranchId) {
                $summary['other_branch']++;

                continue;
            }

            $lastTransaction = isset($sheet['last_transaction_column']) ? $this->parser->parseDate($cells[$sheet['last_transaction_column']] ?? null) : null;

            $pending[$data['account_no']] = [
                'account_no' => $data['account_no'],
                ...array_intersect_key($data, array_flip(self::FIELDS)),
                'branch_id' => $branchId ?? $homeBranchId,
                'last_transaction_date' => $lastTransaction,
                // A category only when the file has one; otherwise it stays empty.
                'category' => isset($sheet['columns']['category']) ? $data['category'] : null,
                'remarks' => $this->remarks($data),
            ];

            if (count($pending) >= self::CHUNK) {
                $this->save($pending, $summary, $importBatchId);
                $pending = [];
            }
        }

        $this->save($pending, $summary, $importBatchId);
    }

    /**
     * The account remark from the row's status or remarks: deceased, deactivated (terminated, withdrawn or closed),
     * or the savings system's dormant-bal / dormant-txn flag; null when the row says none of these.
     *
     * @param  array<string, mixed>  $data  a row normalized by WorkbookParser::normalizeRow()
     */
    private function remarks(array $data): ?string
    {
        return match ($data['terminal_status'] ?? null) {
            'deceased' => 'deceased',
            'terminated', 'withdrawn' => 'deactivated',
            default => $data['savings_account_status'] ?? null,
        };
    }

    /**
     * Insert new members and update existing ones, keeping what is already on file wherever the sheet is blank.
     *
     * @param  array<string, array<string, mixed>>  $rows  keyed by account number
     * @param  array{added: int, updated: int, skipped: int, other_branch: int, sheets: int}  $summary
     */
    private function save(array $rows, array &$summary, ?int $importBatchId): void
    {
        if ($rows === []) {
            return;
        }

        $existing = DB::table('bmpc_members')->whereIn('account_no', array_keys($rows))->get()->keyBy('account_no');

        foreach ($rows as $accountNo => $row) {
            $current = $existing->get($accountNo);

            foreach (self::FIELDS as $field) {
                $row[$field] ??= $current?->{$field};
            }

            // Only a member this upload adds belongs to it; one already on file keeps the upload that added it.
            $row['import_batch_id'] = $current === null ? $importBatchId : $current->import_batch_id;
            $row['imported_by'] = Auth::id();
            $rows[$accountNo] = $row;
            $current === null ? $summary['added']++ : $summary['updated']++;
        }

        BmpcMember::query()->withoutGlobalScopes()->upsert(
            array_values($rows),
            ['account_no'],
            [...self::FIELDS, 'imported_by', 'updated_at'],
        );
    }

    /**
     * Start the Import History entry for an uploaded member list.
     *
     * @param  list<array{name: string}>  $sheets
     */
    public function startBatch(string $fileName, array $sheets): ImportBatch
    {
        return ImportBatch::create([
            'type' => 'bmpc_members',
            'file_name' => $fileName,
            'as_of_date' => now(),
            'status' => 'importing',
            'sheets' => array_map(fn (array $sheet) => ['name' => $sheet['name'], 'include' => true], $sheets),
            'uploaded_by' => Auth::id(),
        ]);
    }

    /**
     * Record the outcome on the Import History entry.
     *
     * @param  array{added: int, updated: int, skipped: int, other_branch: int, sheets: int}  $summary
     */
    public function finishBatch(ImportBatch $batch, array $summary, ?string $errorMessage = null): void
    {
        $batch->update([
            'status' => $errorMessage === null ? 'completed' : 'failed',
            'total_rows' => $summary['added'] + $summary['updated'] + $summary['skipped'] + $summary['other_branch'],
            'new_rows' => $summary['added'],
            'update_rows' => $summary['updated'],
            'invalid_rows' => $summary['skipped'],
            'processed_rows' => $summary['added'] + $summary['updated'],
            'created_count' => $summary['added'],
            'updated_count' => $summary['updated'],
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    /**
     * How many BMPC members deleting this upload would remove.
     */
    public function importedDataCount(ImportBatch $batch): int
    {
        return BmpcMember::query()->withoutGlobalScopes()->where('import_batch_id', $batch->id)->count();
    }

    /**
     * Undo one uploaded member list: permanently delete the BMPC members it added, then remove the upload from
     * Import History. Details it updated on members that were already on file are not rolled back.
     */
    public function deleteImportedData(ImportBatch $batch): int
    {
        return DB::transaction(function () use ($batch) {
            $deleted = BmpcMember::query()->withoutGlobalScopes()->where('import_batch_id', $batch->id)->delete();

            $batch->recordAudit('imported_data_deleted', ['file' => $batch->file_name, 'status' => $batch->status], ['bmpc_members' => $deleted], 'BMPC member import and the members it added were deleted from Import History');
            $batch->delete();

            return $deleted;
        });
    }
}
