<?php

namespace App\Services\Masterlist;

use App\Models\BmpcMember;
use App\Models\Branch;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Reads the coop's Excel list of every Barbaza MPC member into BMPC Members, straight away (no approval).
 * Twelve details are kept: savings number, CIF Key, Account Name, branch, birthdate, contact number, savings
 * balance, last transaction date, segmentation, category, status and remarks; every other column is ignored.
 * Members already on the list are matched by CIF Key and updated; a blank cell never erases a value.
 * Each upload is kept in Import History, and new members remember it so deleting the upload removes them again.
 */
class BmpcMemberImportService
{
    private const CHUNK = 500;

    /**
     * The details saved besides the CIF Key. The name parts are worked out from the Account Name (a new
     * transaction filled in from the member needs them).
     *
     * @var list<string>
     */
    private const FIELDS = [
        'savings_no', 'account_name', 'last_name', 'first_name', 'middle_name', 'suffix', 'branch_id', 'birthdate', 'contact_number',
        'savings_balance', 'last_transaction_date', 'segment', 'category', 'status', 'remarks',
    ];

    /**
     * The details staff match to a header of the file on the Import button, in table column order.
     *
     * @var array<string, string>
     */
    public const MAPPABLE_FIELDS = [
        'savings_no' => 'Savings number',
        'cif_key' => 'CIF Key',
        'account_name' => 'Account Name',
        'branch' => 'Branch',
        'birthdate' => 'Birthdate',
        'contact_number' => 'Contact Number',
        'savings_balance' => 'Savings Balance',
        'last_transaction_date' => 'Last Transaction Date',
        'segment' => 'Segmentation',
        'category' => 'Category',
        'status' => 'Status',
        'remarks' => 'Remarks',
    ];

    /**
     * Headers (lower case, letters and digits only) that hold the CIF Key, the member's unique number.
     *
     * @var list<string>
     */
    private const CIF_KEY_HEADERS = ['cifkey', 'cif', 'cifno', 'cifnumber', 'cifkeyno', 'cid', 'cidno', 'memberid', 'memberno', 'membersno', 'membernumber'];

    /**
     * Headers that hold the savings account number.
     *
     * @var list<string>
     */
    private const SAVINGS_NO_HEADERS = [
        'savingsnumber', 'savingsno', 'savingsnum', 'savingsacctno', 'savingsaccountno', 'savingsaccountnumber', 'savingsacct', 'savingsaccount',
        'saacctno', 'sano', 'sanumber', 'acctno', 'acctnumber', 'accountno', 'accountnumber', 'accno',
    ];

    /**
     * The savings number option that builds the number from three columns: the savings system's export has the
     * branch code (Br, "002"), product group (Grp, "01") and account number (AcctNo., "000016") apart, and its own
     * "Savings Number" column is a formula joining them that Excel saves without the result.
     */
    public const BUILT_SAVINGS_NO = 'Br + Grp + AcctNo. (joined)';

    public function __construct(
        private WorkbookParser $parser,
        private NameParser $nameParser,
    ) {}

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
     * The sheets that hold a member list: a CIF Key column and an Account Name column.
     *
     * @return list<array{index: int, name: string, header_row: int, headers: array<int, string>, columns: array<string, int>, branch_id: ?int, contact_columns: list<int>}>
     */
    public function sheets(string $path): array
    {
        return array_values(array_filter($this->mappableSheets($path), fn (array $sheet) => self::hasMemberColumns($sheet['columns'])));
    }

    /**
     * Every sheet with a header row, each detail matched to a header automatically; the Import button lets staff
     * change the matching (see applyMapping()) before anything is read.
     *
     * @return list<array{index: int, name: string, header_row: int, headers: array<int, string>, columns: array<string, int>, branch_id: ?int, contact_columns: list<int>}>
     */
    public function mappableSheets(string $path): array
    {
        $sheets = [];

        foreach ($this->parser->detectSheets($path) as $sheet) {
            if ($sheet['header_row'] !== null) {
                $sheets[] = [
                    ...array_intersect_key($sheet, array_flip(['index', 'name', 'header_row', 'headers', 'branch_id'])),
                    'columns' => $this->detectColumns($sheet['headers']),
                    'contact_columns' => $this->contactColumns($sheet['headers']),
                    'savings_no_parts' => $this->savingsNumberParts($sheet['headers']),
                ];
            }
        }

        return $sheets;
    }

    /**
     * The Br, Grp and AcctNo. columns the savings number is built from, when the sheet has all three.
     *
     * @param  array<int, string>  $headers
     * @return ?list<int>
     */
    private function savingsNumberParts(array $headers): ?array
    {
        $normalized = array_map(fn (string $header) => preg_replace('/[^a-z0-9]/', '', strtolower($header)) ?? '', $headers);
        $parts = [];

        foreach ([['br', 'brcode', 'branchcode'], ['grp', 'grpcode', 'group', 'productgroup'], ['acctno', 'acctnumber', 'accountno', 'accountnumber']] as $names) {
            $column = array_key_first(array_filter($normalized, fn (string $header) => in_array($header, $names, true)));

            if ($column === null) {
                return null;
            }

            $parts[] = $column;
        }

        return $parts;
    }

    /**
     * Whether any sheet can have its savings number built from Br + Grp + AcctNo.
     *
     * @param  list<array{savings_no_parts?: ?list<int>}>  $sheets
     */
    public function canBuildSavingsNumber(array $sheets): bool
    {
        return collect($sheets)->contains(fn (array $sheet) => ($sheet['savings_no_parts'] ?? null) !== null);
    }

    /**
     * Which column holds each detail, from the headers.
     *
     * @param  array<int, string>  $headers
     * @return array<string, int> field of MAPPABLE_FIELDS => column
     */
    private function detectColumns(array $headers): array
    {
        $normalized = array_map(fn (string $header) => preg_replace('/[^a-z0-9]/', '', strtolower($header)) ?? '', $headers);
        $firstColumn = fn (array $names): ?int => array_key_first(array_filter($normalized, fn (string $header) => in_array($header, $names, true)));
        // A "Savings Number" header before a plain "Acct No.", wherever each one is.
        $savingsNoColumn = collect(self::SAVINGS_NO_HEADERS)->map(fn (string $name) => array_search($name, $normalized, true))->first(fn (int|false $column) => $column !== false);
        $cifKeyColumn = $firstColumn(self::CIF_KEY_HEADERS);
        // A detail WorkbookParser recognizes by its header, never the savings number or CIF Key column ("Savings
        // Number" would otherwise read as a savings balance).
        $matched = fn (string $field): ?int => array_key_first(array_filter(
            $headers,
            fn (string $header, int $column) => ! in_array($column, [$savingsNoColumn, $cifKeyColumn], true) && $this->parser->matchHeader($header) === $field,
            ARRAY_FILTER_USE_BOTH,
        ));

        $columns = [
            'savings_no' => $savingsNoColumn,
            'cif_key' => $cifKeyColumn,
            'account_name' => $matched('full_name'),
            'branch' => $firstColumn(['branch', 'branchname', 'branchcode', 'br', 'brcode', 'originbranch', 'homebranch', 'memberbranch']),
            'birthdate' => $matched('birthdate'),
            'contact_number' => $this->contactColumns($headers)[0] ?? null,
            'savings_balance' => $matched('savings_balance'),
            // The savings system's export calls it "LastOTCDate" (last over-the-counter transaction).
            'last_transaction_date' => $this->parser->lastTransactionColumn($headers) ?? $firstColumn(['lastotcdate', 'lastotc']),
            // The coop list has "Classification" (MIGS / NON-MIGS) before "Segmentation Status" (Diamond, Gold,
            // Silver): the segmentation is the column that says so.
            'segment' => array_key_first(array_filter($normalized, fn (string $header) => str_contains($header, 'segment'))) ?? $matched('segment'),
            'category' => $matched('category'),
            // A plain "Status" before one the parser only guesses from the word ("Civil Status").
            'status' => $firstColumn(['status', 'memberstatus', 'membershipstatus', 'accountstatus', 'acctstatus']) ?? $matched('status') ?? $matched('savings_account_status'),
            'remarks' => $matched('remarks'),
        ];

        return array_filter($columns, fn (?int $column) => $column !== null);
    }

    /**
     * A CIF Key column and an Account Name column.
     *
     * @param  array<string, int|null>  $columns
     */
    private static function hasMemberColumns(array $columns): bool
    {
        return isset($columns['cif_key'], $columns['account_name']);
    }

    /**
     * The header each detail is read from, as found automatically: from the first sheet that has the detail.
     *
     * @param  list<array{headers: array<int, string>, columns: array<string, int>}>  $sheets
     * @return array<string, ?string> field => header text, null when no sheet has it
     */
    public function suggestedMapping(array $sheets): array
    {
        $mapping = array_fill_keys(array_keys(self::MAPPABLE_FIELDS), null);

        foreach ($sheets as $sheet) {
            foreach ($mapping as $field => $header) {
                if ($header === null && isset($sheet['columns'][$field], $sheet['headers'][$sheet['columns'][$field]])) {
                    $mapping[$field] = $sheet['headers'][$sheet['columns'][$field]];
                }
            }
        }

        // The savings system's export: its "Savings Number" column is a formula without its result.
        if ($this->canBuildSavingsNumber($sheets)) {
            $mapping['savings_no'] = self::BUILT_SAVINGS_NO;
        }

        return $mapping;
    }

    /**
     * Every header in the workbook, once each, in the order they first appear.
     *
     * @param  list<array{headers: array<int, string>}>  $sheets
     * @return list<string>
     */
    public function headerOptions(array $sheets): array
    {
        return array_values(array_unique(array_merge(...array_map(fn (array $sheet) => array_values($sheet['headers']), $sheets ?: [['headers' => []]]))));
    }

    /**
     * Read each detail from the header staff chose, in every sheet that has that header (matched ignoring case and
     * spaces); a detail left empty is not read. Sheets without a CIF Key and an Account Name column are left out.
     *
     * @param  list<array{index: int, name: string, header_row: int, headers: array<int, string>, columns: array<string, int>, branch_id: ?int, contact_columns: list<int>}>  $sheets
     * @param  array<string, ?string>  $mapping  field => header text
     * @return list<array{index: int, name: string, header_row: int, headers: array<int, string>, columns: array<string, int>, branch_id: ?int, contact_columns: list<int>}>
     */
    public function applyMapping(array $sheets, array $mapping): array
    {
        $key = fn (?string $header): string => preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $header))) ?? '';
        $mapped = [];

        foreach ($sheets as $sheet) {
            $columnsByHeader = [];

            foreach ($sheet['headers'] as $column => $header) {
                $columnsByHeader[$key($header)] ??= $column;
            }

            $columns = [];

            foreach (array_keys(self::MAPPABLE_FIELDS) as $field) {
                if (filled($mapping[$field] ?? null) && isset($columnsByHeader[$key($mapping[$field])])) {
                    $columns[$field] = $columnsByHeader[$key($mapping[$field])];
                }
            }

            if (! self::hasMemberColumns($columns)) {
                continue;
            }

            $contactColumn = $columns['contact_number'] ?? null;

            $mapped[] = [
                ...$sheet,
                'columns' => $columns,
                'savings_no_parts' => ($mapping['savings_no'] ?? null) === self::BUILT_SAVINGS_NO ? ($sheet['savings_no_parts'] ?? null) : null,
                // The chosen phone column first; the sheet's other phone columns still fill in a blank one.
                'contact_columns' => $contactColumn === null ? [] : [$contactColumn, ...array_values(array_diff($sheet['contact_columns'], [$contactColumn]))],
            ];
        }

        return $mapped;
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
     * Import one sheet found by sheets() or applyMapping(), adding its counts to the summary.
     *
     * @param  array{index: int, name: string, header_row: int, columns: array<string, int>, branch_id: ?int, contact_columns?: list<int>}  $sheet
     * @param  array{added: int, updated: int, skipped: int, other_branch: int, sheets: int}  $summary
     * @param  ?int  $importBatchId  the Import History entry new members are recorded against
     */
    public function importSheet(string $path, array $sheet, array &$summary, ?int $importBatchId = null): void
    {
        $homeBranchId = Branch::restrictedId();
        $branchesByCode = Branch::query()->whereNotNull('code')->pluck('id', 'code')->all();
        $branchesByText = [];
        $columns = $sheet['columns'];
        $summary['sheets']++;
        $pending = [];

        // The details WorkbookParser reads and cleans up (names, dates, amounts, segmentation, category).
        $parserColumns = array_filter([
            'account_no' => $columns['cif_key'] ?? null,
            'full_name' => $columns['account_name'] ?? null,
            'birthdate' => $columns['birthdate'] ?? null,
            'savings_balance' => $columns['savings_balance'] ?? null,
            'segment' => $columns['segment'] ?? null,
            'category' => $columns['category'] ?? null,
        ], fn (?int $column) => $column !== null);
        $text = fn (array $cells, string $field): ?string => isset($columns[$field]) ? (trim($this->parser->cellToString($cells[$columns[$field]] ?? null)) ?: null) : null;

        foreach ($this->parser->rows($path, $sheet['index'], $sheet['header_row']) as $cells) {
            $row = $this->parser->normalizeRow($cells, $parserColumns, allowNegativeBalance: true);

            if ($row['blank']) {
                continue;
            }

            $data = $row['data'];
            // "SURNAME, FIRST MIDDLE", also when the file left the surname part blank (", JEAN A NARCISO KIS").
            $data['account_name'] = $data['account_name'] !== null ? ($this->nameParser->surnameFirst($data['account_name']) ?: null) : null;
            // A stray period typed after a number ("00400000SN8168.") is not part of it.
            $cifKey = rtrim((string) $data['account_no'], '.') ?: null;
            $savingsNo = $this->savingsNumber($cells, $sheet);

            if ($cifKey === null || ! preg_match('/^[0-9A-Za-z\-]{4,30}$/', $cifKey) || $data['account_name'] === null) {
                $summary['skipped']++;

                continue;
            }

            foreach ($sheet['contact_columns'] ?? [] as $column) {
                if ($contactNumber = $this->parser->parseContactNumber($this->parser->cellToString($cells[$column] ?? null))) {
                    $data['contact_number'] = $contactNumber;

                    break;
                }
            }

            // The Branch column (a branch code or name) when there is one, else the branch code in the first three
            // digits of the savings number or CIF Key, else the sheet's name.
            $branchText = $text($cells, 'branch');
            $branchId = ($branchText !== null ? $branchesByText[$branchText] ??= Branch::resolve($branchText)?->id : null)
                ?? $branchesByCode[substr((string) $savingsNo, 0, 3)]
                ?? $branchesByCode[substr($cifKey, 0, 3)]
                ?? $sheet['branch_id'];

            if ($homeBranchId !== null && $branchId !== null && $branchId !== $homeBranchId) {
                $summary['other_branch']++;

                continue;
            }

            $pending[$cifKey] = [
                'cif_key' => $cifKey,
                ...array_intersect_key($data, array_flip(self::FIELDS)),
                'last_name' => $data['last_name'] ?: null,
                'first_name' => $data['first_name'] ?: null,
                'savings_no' => $savingsNo !== null ? mb_substr($savingsNo, 0, 30) : null,
                'branch_id' => $branchId ?? $homeBranchId,
                'contact_number' => $data['contact_number'] ?? null,
                'last_transaction_date' => isset($columns['last_transaction_date']) ? $this->parser->parseDate($cells[$columns['last_transaction_date']] ?? null) : null,
                // A category only when the file has one; otherwise it stays empty.
                'category' => isset($columns['category']) ? $data['category'] : null,
                // Status and Remarks are kept as the file writes them.
                'status' => ($status = $text($cells, 'status')) !== null ? mb_substr($status, 0, 100) : null,
                'remarks' => ($remarks = $text($cells, 'remarks')) !== null ? mb_substr($remarks, 0, 255) : null,
            ];

            if (count($pending) >= self::CHUNK) {
                $this->save($pending, $summary, $importBatchId);
                $pending = [];
            }
        }

        $this->save($pending, $summary, $importBatchId);
    }

    /**
     * The row's savings number: joined from Br + Grp + AcctNo. ("002" + "01" + "000016"), or read from the chosen
     * column. A formula Excel saved without its result is no number.
     *
     * @param  array<int, mixed>  $cells
     * @param  array{columns: array<string, int>, savings_no_parts?: ?list<int>}  $sheet
     */
    private function savingsNumber(array $cells, array $sheet): ?string
    {
        $read = function (?int $column) use ($cells): ?string {
            $value = rtrim((string) $this->parser->normalizeAccountNumber($column === null ? null : ($cells[$column] ?? null)), '.');

            return $value === '' || str_starts_with($value, '=') ? null : $value;
        };

        if ($parts = $sheet['savings_no_parts'] ?? null) {
            $pieces = [];

            // Excel drops leading zeros from a number cell: put them back to the parts' usual widths.
            foreach (array_combine($parts, [3, 2, 6]) as $column => $width) {
                if (($piece = $read($column)) === null) {
                    return null;
                }

                $pieces[] = ctype_digit($piece) ? str_pad($piece, $width, '0', STR_PAD_LEFT) : $piece;
            }

            return implode('', $pieces);
        }

        return $read($sheet['columns']['savings_no'] ?? null);
    }

    /**
     * Insert new members and update existing ones, keeping what is already on file wherever the sheet is blank.
     *
     * @param  array<string, array<string, mixed>>  $rows  keyed by CIF Key
     * @param  array{added: int, updated: int, skipped: int, other_branch: int, sheets: int}  $summary
     */
    private function save(array $rows, array &$summary, ?int $importBatchId): void
    {
        if ($rows === []) {
            return;
        }

        $existing = DB::table('bmpc_members')->whereIn('cif_key', array_keys($rows))->get()->keyBy('cif_key');

        foreach ($rows as $cifKey => $row) {
            $current = $existing->get($cifKey);

            foreach (self::FIELDS as $field) {
                $row[$field] ??= $current?->{$field};
            }

            // Only a member this upload adds belongs to it; one already on file keeps the upload that added it.
            $row['import_batch_id'] = $current === null ? $importBatchId : $current->import_batch_id;
            $row['imported_by'] = Auth::id();
            $rows[$cifKey] = $row;
            $current === null ? $summary['added']++ : $summary['updated']++;
        }

        BmpcMember::query()->withoutGlobalScopes()->upsert(
            array_values($rows),
            ['cif_key'],
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
