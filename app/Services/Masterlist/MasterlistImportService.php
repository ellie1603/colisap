<?php

namespace App\Services\Masterlist;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Services\Policy\PolicySettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Masterlist import pipeline (no row limit, no queue worker required):
 *   createBatch()  upload → sheet detection, branch & column suggestions
 *   stageSheet()   stream one sheet into import_rows with validation (call once per sheet)
 *   finalize()     apply side lists, detect duplicates, classify new / update / invalid
 *   importChunk()  write the next N rows into members (call repeatedly until it returns 0)
 * Each step is short, so the browser drives progress and nothing times out.
 */
class MasterlistImportService
{
    public const DISK = 'local';

    public const DIRECTORY = 'masterlist-imports';

    private const INSERT_CHUNK = 500;

    public function __construct(
        private WorkbookParser $parser,
        private PolicySettings $policy,
        private StatusTextMapper $statusText,
    ) {}

    /**
     * Balances and statuses in the workbook are taken as of the upload date unless a date is given.
     */
    public function createBatch(string $storedPath, string $originalName, Carbon|string|null $asOfDate = null): ImportBatch
    {
        $sheets = $this->parser->detectSheets(Storage::disk(self::DISK)->path($storedPath));

        $batch = ImportBatch::create([
            'file_name' => $originalName,
            'file_path' => $storedPath,
            'as_of_date' => $asOfDate ?? now()->toDateString(),
            'status' => 'analyzed',
            'sheets' => array_map(fn (array $sheet) => [...$sheet, 'staged' => false, 'staged_rows' => 0], $sheets),
            'uploaded_by' => Auth::id(),
        ]);

        return $batch;
    }

    /**
     * Sheets the user chose to import, in workbook order.
     *
     * @return list<int> positions in $batch->sheets
     */
    public function includedSheetPositions(ImportBatch $batch): array
    {
        return array_keys(array_filter($batch->sheets ?? [], fn (array $sheet) => ($sheet['include'] ?? false) && ($sheet['header_row'] ?? null)));
    }

    /**
     * Clear any previous staging so mapping changes can be re-validated.
     */
    public function resetStaging(ImportBatch $batch): void
    {
        $batch->rows()->delete();
        $batch->update([
            'status' => 'analyzed',
            'sheets' => array_map(fn (array $sheet) => [...$sheet, 'staged' => false, 'staged_rows' => 0], $batch->sheets ?? []),
            'total_rows' => 0, 'new_rows' => 0, 'update_rows' => 0, 'duplicate_rows' => 0, 'invalid_rows' => 0, 'warning_rows' => 0,
        ]);
    }

    /**
     * Stream one sheet into import_rows. Returns the number of member rows staged.
     */
    public function stageSheet(ImportBatch $batch, int $position): int
    {
        $sheets = $batch->sheets;
        $sheet = $sheets[$position];
        $columns = array_map('intval', array_filter($sheet['columns'] ?? [], fn ($column) => $column !== null && $column !== ''));
        $allowNegative = $this->policy->bool('allow_negative_balance');
        $path = Storage::disk(self::DISK)->path($batch->file_path);

        $buffer = [];
        $references = [];
        $numericAccountRows = [];
        $lengthTally = [];
        $staged = 0;
        $now = now();

        foreach ($this->parser->rows($path, $sheet['index'], (int) $sheet['header_row']) as $rowNumber => $cells) {
            foreach ($this->parser->sideListEntries($cells, array_values($columns)) as $entry) {
                $references[] = $this->stagedRow($batch, $sheet, $rowNumber, $now, [
                    'account_no' => $entry['account_no'],
                    'account_name' => $entry['account_name'],
                    'action' => 'reference',
                    'status' => 'skipped',
                    'data' => ['kind' => $entry['kind'], 'value' => $entry['value']],
                ]);
            }

            $normalized = $this->parser->normalizeRow($cells, $columns, $allowNegative);

            if ($normalized['blank']) {
                continue;
            }

            if (! isset($normalized['raw']['category']) && ($normalized['data']['category'] ?? null) === null) {
                $normalized['warnings'][] = 'No category — new members default to 40K';
            }

            $row = $this->stagedRow($batch, $sheet, $rowNumber, $now, [
                'account_no' => $normalized['data']['account_no'],
                'account_name' => $normalized['data']['account_name'],
                'action' => $normalized['errors'] === [] ? 'pending' : 'invalid',
                'status' => $normalized['errors'] === [] ? 'pending' : 'skipped',
                'data' => $normalized['data'],
                'raw' => $normalized['raw'],
                'errors' => $normalized['errors'],
                'warnings' => $normalized['warnings'],
            ]);

            $staged++;

            if ($normalized['numeric_account'] && $normalized['data']['account_no'] !== null) {
                $numericAccountRows[] = $row;
            } else {
                if ($normalized['data']['account_no'] !== null) {
                    $length = strlen($normalized['data']['account_no']);
                    $lengthTally[$length] = ($lengthTally[$length] ?? 0) + 1;
                }

                $buffer[] = $row;
            }
        }

        // Account numbers typed as numbers in Excel lose their leading zeros; restore them to the sheet's usual length.
        $usualLength = $lengthTally === [] ? null : array_search(max($lengthTally), $lengthTally, true);

        foreach ($numericAccountRows as $row) {
            $data = json_decode($row['data'], true);
            $warnings = json_decode($row['warnings'], true) ?? [];

            if ($usualLength && strlen($data['account_no']) < $usualLength) {
                $data['account_no'] = str_pad($data['account_no'], $usualLength, '0', STR_PAD_LEFT);
                $warnings[] = "Acct. Number was stored as a number in Excel; leading zeros restored ({$data['account_no']})";
            } elseif (! $usualLength) {
                $warnings[] = 'Acct. Number was stored as a number in Excel — verify leading zeros';
            }

            $buffer[] = [...$row, 'account_no' => $data['account_no'], 'data' => json_encode($data), 'warnings' => json_encode($warnings)];
        }

        // Side lists sometimes carry an extra leading zero (e.g. 001001009118 for 01001009118); align them with the sheet.
        $sideValues = [];

        foreach ($references as $index => $reference) {
            if ($usualLength && strlen($reference['account_no']) > $usualLength && str_starts_with($reference['account_no'], '0')) {
                $trimmed = ltrim($reference['account_no'], '0');
                $references[$index]['account_no'] = strlen($trimmed) <= $usualLength ? str_pad($trimmed, $usualLength, '0', STR_PAD_LEFT) : $reference['account_no'];
            }

            $entry = json_decode($reference['data'], true);
            $sideValues[$references[$index]['account_no']][$entry['kind']] ??= $entry['value'];
        }

        // A sheet's side lists mostly describe its own members: apply them here, in memory, before anything is written.
        $appliedAccounts = [];

        foreach ($buffer as $index => $row) {
            $side = $row['action'] === 'pending' ? ($sideValues[$row['account_no']] ?? null) : null;

            if ($side === null) {
                continue;
            }

            $appliedAccounts[$row['account_no']] = true;
            [$data, $warnings] = $this->withSideValues(json_decode($row['data'], true), json_decode($row['warnings'], true) ?? [], $side);
            $buffer[$index]['data'] = json_encode($data);
            $buffer[$index]['warnings'] = json_encode($warnings);
        }

        // Entries for other accounts stay as references: they may belong to another sheet or to a member already in the system.
        foreach ($references as $reference) {
            if (! isset($appliedAccounts[$reference['account_no']])) {
                $buffer[] = $reference;
            }
        }

        foreach (array_chunk($buffer, self::INSERT_CHUNK) as $chunk) {
            ImportRow::insert($chunk);
        }

        $sheets[$position]['staged'] = true;
        $sheets[$position]['staged_rows'] = $staged;
        $batch->update(['sheets' => $sheets]);

        return $staged;
    }

    /**
     * @param  array<string, mixed>  $sheet
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function stagedRow(ImportBatch $batch, array $sheet, int $rowNumber, Carbon $now, array $values): array
    {
        return [
            'import_batch_id' => $batch->id,
            'sheet' => $sheet['name'],
            'row_number' => $rowNumber,
            'branch_id' => $sheet['branch_id'] ?? null,
            'account_no' => $values['account_no'],
            'account_name' => isset($values['account_name']) ? mb_substr((string) $values['account_name'], 0, 250) : null,
            'action' => $values['action'],
            'status' => $values['status'],
            'data' => json_encode($values['data'] ?? null),
            'raw' => json_encode($values['raw'] ?? null),
            'errors' => json_encode($values['errors'] ?? []),
            'warnings' => json_encode($values['warnings'] ?? []),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Apply side lists and classify every staged row. Runs set-based queries so large files stay fast.
     */
    public function finalize(ImportBatch $batch): ImportBatch
    {
        Branch::withoutRestriction(fn () => $this->applySideLists($batch));

        $pending = fn () => ImportRow::query()->where('import_batch_id', $batch->id)->where('action', 'pending');

        // Duplicate Acct. Numbers within the file: the first occurrence wins.
        $duplicateIds = DB::table('import_rows as r')
            ->join(DB::raw('(select account_no, min(id) as first_id from import_rows where import_batch_id = '.(int) $batch->id." and action = 'pending' group by account_no) as g"), function ($join) {
                $join->on('g.account_no', '=', 'r.account_no')->whereColumn('r.id', '<>', 'g.first_id');
            })
            ->where('r.import_batch_id', $batch->id)
            ->where('r.action', 'pending')
            ->pluck('r.id');

        foreach ($duplicateIds->chunk(1000) as $ids) {
            ImportRow::whereIn('id', $ids->all())->update([
                'action' => 'duplicate',
                'status' => 'skipped',
                'errors' => json_encode(['Acct. Number appears more than once in the file; only the first occurrence is imported']),
            ]);
        }

        // Existing members (including archived) are updated, never duplicated.
        $pending()->whereExists(fn ($query) => $query->selectRaw('1')->from('members')->whereColumn('members.account_no', 'import_rows.account_no'))
            ->update([
                'action' => 'update',
                'member_id' => DB::raw('(select members.id from members where members.account_no = import_rows.account_no limit 1)'),
            ]);

        // New members must have an approval date to start the waiting period.
        $pending()->whereNull('data->approval_date')->update([
            'action' => 'invalid',
            'status' => 'skipped',
            'errors' => json_encode(['New member has no approval (NEW) date']),
        ]);

        $pending()->update(['action' => 'new']);

        return $this->refreshCounts($batch, status: 'staged');
    }

    private function applySideLists(ImportBatch $batch): void
    {
        $sheets = $batch->sheets;
        $hasDormancyInfo = collect($sheets)->contains(fn (array $sheet) => ($sheet['include'] ?? false) && isset($sheet['columns']['savings_account_status']))
            || ImportRow::where('import_batch_id', $batch->id)->where('action', 'reference')->where('data->kind', 'flag')->exists();

        $references = fn () => DB::table('import_rows')->where('import_batch_id', $batch->id)->where('action', 'reference');

        // 1. Read every side-list entry once: account → kind → value (the first entry of each kind wins).
        //    A branch's savings list covers all depositors, so most entries belong to people who are not COLISAP members.
        $sideValues = [];

        foreach ($references()->orderBy('id')->select(['account_no', 'data'])->cursor() as $reference) {
            $entry = json_decode($reference->data, true);
            $sideValues[$reference->account_no][$entry['kind']] ??= $entry['value'];
        }

        if ($sideValues !== []) {
            // 2. Apply them to the member rows of this file.
            $matchedAccounts = [];

            ImportRow::query()
                ->where('import_batch_id', $batch->id)
                ->where('action', 'pending')
                ->select(['id', 'account_no', 'data', 'warnings'])
                ->chunkById(1000, function ($rows) use (&$matchedAccounts, $sideValues) {
                    DB::transaction(function () use ($rows, &$matchedAccounts, $sideValues) {
                        foreach ($rows as $row) {
                            $side = $sideValues[$row->account_no] ?? null;

                            if ($side === null) {
                                continue;
                            }

                            $matchedAccounts[$row->account_no] = true;
                            [$data, $warnings] = $this->withSideValues($row->data, $row->warnings ?? [], $side);

                            $row->update(['data' => $data, 'warnings' => $warnings]);
                        }
                    });
                });

            // 3. Book-keep the side-list rows in bulk: applied, kept for an existing member, or dropped as noise.
            $unmatched = array_map('strval', array_keys(array_diff_key($sideValues, $matchedAccounts)));

            foreach (array_chunk(array_map('strval', array_keys($matchedAccounts)), 1000) as $accounts) {
                $references()->whereIn('account_no', $accounts)->update([
                    'status' => 'skipped',
                    'member_id' => null,
                    'warnings' => json_encode(['Applied to this member\'s row in the branch sheet']),
                ]);
            }

            foreach (array_chunk($unmatched, 1000) as $accounts) {
                $existing = Member::withTrashed()->whereIn('account_no', $accounts)->pluck('id', 'account_no');

                foreach ($existing as $accountNo => $memberId) {
                    $references()->where('account_no', (string) $accountNo)->update([
                        'member_id' => $memberId,
                        'status' => 'pending',
                        'warnings' => json_encode(['Listed in a side list only — existing member will be updated with this value']),
                    ]);
                }

                $references()->whereIn('account_no', array_values(array_diff($accounts, array_map('strval', $existing->keys()->all()))))->delete();
            }
        }

        foreach ($sheets as $position => $sheet) {
            $sheets[$position]['dormancy_source'] = $hasDormancyInfo;
        }

        $batch->update(['sheets' => $sheets]);
    }

    /**
     * Fill a member row from side-list values without overriding what the row already states.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $side  kind (flag|terminal|segment|balance) => value
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function withSideValues(array $data, array $warnings, array $side): array
    {
        $data['savings_account_status'] ??= $side['flag'] ?? null;
        $data['terminal_status'] ??= $side['terminal'] ?? null;
        $data['segment'] ??= $side['segment'] ?? null;
        $data['savings_balance'] ??= isset($side['balance']) ? (float) $side['balance'] : null;

        if (isset($side['segment'])) {
            $warnings = array_values(array_filter($warnings, fn (string $warning) => ! str_contains($warning, 'segmentation')));
        }

        return [$data, $warnings];
    }

    public function refreshCounts(ImportBatch $batch, ?string $status = null): ImportBatch
    {
        $counts = ImportRow::query()
            ->where('import_batch_id', $batch->id)
            ->where('action', '<>', 'reference')
            ->selectRaw('action, count(*) as total')
            ->groupBy('action')
            ->pluck('total', 'action');

        $warnings = ImportRow::query()
            ->where('import_batch_id', $batch->id)
            ->where(fn ($query) => $query->whereNotNull('warnings')->where('warnings', '<>', '[]')->where('warnings', '<>', 'null'))
            ->count();

        $batch->update(array_filter([
            'status' => $status,
            'total_rows' => (int) $counts->sum(),
            'new_rows' => (int) ($counts['new'] ?? 0),
            'update_rows' => (int) ($counts['update'] ?? 0) + ImportRow::where('import_batch_id', $batch->id)->where('action', 'reference')->where('status', 'pending')->count(),
            'duplicate_rows' => (int) ($counts['duplicate'] ?? 0),
            'invalid_rows' => (int) ($counts['invalid'] ?? 0),
            'warning_rows' => $warnings,
        ], fn ($value) => $value !== null));

        return $batch->refresh();
    }

    /**
     * Write the next chunk of staged rows into the member database. Returns rows processed (0 = done).
     */
    public function importChunk(ImportBatch $batch, int $limit = 200): int
    {
        if ($batch->status === 'staged') {
            $batch->update(['status' => 'importing']);
        }

        $rows = ImportRow::query()
            ->where('import_batch_id', $batch->id)
            ->where('status', 'pending')
            ->whereIn('action', ['new', 'update', 'reference'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->complete($batch);

            return 0;
        }

        $tally = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        // One commit per chunk (each row is still its own savepoint) and no per-record audit entries:
        // the batch, its source rows and the member change history are the import's trail.
        DB::transaction(function () use ($batch, $rows, &$tally) {
            AuditLog::withoutRecording(function () use ($batch, $rows, &$tally) {
                foreach ($rows as $row) {
                    try {
                        // Acct. Numbers are matched across every branch, whoever runs the import, so no member is created twice.
                        $result = Branch::withoutRestriction(fn () => DB::transaction(fn () => $this->importRow($batch, $row)));
                        $tally[$result]++;
                    } catch (Throwable $exception) {
                        report($exception);

                        $row->update([
                            'status' => 'skipped',
                            'errors' => [...($row->errors ?? []), 'Import failed: '.$exception->getMessage()],
                        ]);
                    }
                }
            });
        });

        $batch->update([
            'processed_rows' => $batch->processed_rows + $rows->count(),
            'created_count' => $batch->created_count + $tally['created'],
            'updated_count' => $batch->updated_count + $tally['updated'],
            'unchanged_count' => $batch->unchanged_count + $tally['unchanged'],
        ]);

        return $rows->count();
    }

    /**
     * @return 'created'|'updated'|'unchanged'
     */
    private function importRow(ImportBatch $batch, ImportRow $row): string
    {
        $asOf = $batch->as_of_date;
        $data = $row->action === 'reference'
            ? [$this->referenceField($row->data['kind']) => $row->data['value'], 'account_no' => $row->account_no]
            : $row->data;

        $member = Member::withTrashed()->where('account_no', $row->account_no)->first();
        $isNew = $member === null;

        $member ??= new Member([
            'account_no' => $row->account_no,
            'status' => 'waiting',
            'category' => $data['category'] ?? '40000',
        ]);

        $member->fill(array_filter([
            'account_name' => $data['account_name'] ?? null,
            'first_name' => ($data['first_name'] ?? '') !== '' ? $data['first_name'] : null,
            'last_name' => ($data['last_name'] ?? '') !== '' ? $data['last_name'] : null,
            'branch_id' => $row->action === 'reference' ? null : $row->branch_id,
            'segment' => $data['segment'] ?? null,
            'category' => $data['category'] ?? null,
            'application_date' => $data['application_date'] ?? null,
            'approval_date' => $data['approval_date'] ?? null,
            'birthdate' => $data['birthdate'] ?? null,
            'sex' => $data['sex'] ?? null,
            'contact_number' => $data['contact_number'] ?? null,
            'address' => $data['address'] ?? null,
        ], fn ($value) => $value !== null));

        if (($data['first_name'] ?? '') !== '' && ($data['last_name'] ?? '') !== '') {
            $member->middle_name = $data['middle_name'] ?? null;
            $member->suffix = $data['suffix'] ?? null;
        }

        if (! empty($data['upgrade_requested_at']) && $member->category === '40000') {
            $member->category_upgrade_requested_at = $data['upgrade_requested_at'];
        }

        $dormancySource = (bool) ($batch->sheets[0]['dormancy_source'] ?? false);

        if (array_key_exists('savings_account_status', $data) && ($data['savings_account_status'] !== null || ($dormancySource && $row->action !== 'reference'))) {
            $member->savings_account_status = $data['savings_account_status'];

            if ($data['savings_account_status'] === null) {
                $member->dormant_since = null;
            }
        }

        if (! in_array($member->status, Member::TERMINAL_STATUSES, true)) {
            if (($data['terminal_status'] ?? null) === 'deceased') {
                $member->status = 'deceased';
                $member->date_deceased ??= $data['date_deceased'] ?? $asOf;
            } elseif (($data['terminal_status'] ?? null) === 'withdrawn') {
                $member->status = 'withdrawn';
                $member->withdrawn_at ??= $asOf;
                $member->withdrawal_reason ??= isset($data['remarks']) && $this->statusText->isTransfer($data['remarks'])
                    ? "Branch transfer — {$data['remarks']}"
                    : 'Recorded in masterlist'.(isset($data['remarks']) ? " (remarks: {$data['remarks']})" : '');
            } elseif (($data['terminal_status'] ?? null) === 'terminated') {
                $member->status = 'terminated';
                $member->terminated_at ??= $asOf;
                $member->termination_reason ??= 'Recorded in masterlist'.(isset($data['remarks']) ? " (remarks: {$data['remarks']})" : '');
            }
        }

        $member->fill([
            'import_batch_id' => $batch->id,
            'source_sheet' => $row->sheet,
            'source_row' => $row->row_number,
            'source_values' => $row->raw ?: $row->data,
        ]);

        if (($data['savings_balance'] ?? null) !== null) {
            $member->savings_balance_as_of = $asOf;
        }

        $meaningfulChanges = array_diff(array_keys($member->getDirty()), ['import_batch_id', 'source_sheet', 'source_row', 'source_values', 'savings_balance_as_of', 'updated_by']);
        $changed = $isNew || $meaningfulChanges !== [];

        if ($member->trashed()) {
            $member->restore();
            $changed = true;
        }

        $member->withChangeReason("Masterlist import #{$batch->id} ({$row->sheet} row {$row->row_number})", 'import')->save();

        if ($isNew && $member->approval_date) {
            Application::create([
                'member_id' => $member->id,
                'type' => 'new',
                'category' => $member->category,
                'application_date' => $member->application_date ?? $member->approval_date,
                'status' => 'approved',
                'approved_at' => $member->approval_date,
                'remarks' => "Recorded from masterlist import #{$batch->id}",
            ]);
        }

        $delta = ($data['savings_balance'] ?? null) === null ? 0.0 : round((float) $data['savings_balance'] - (float) $member->savings_balance, 2);

        if (abs($delta) >= 0.01) {
            SavingsTransaction::create([
                'member_id' => $member->id,
                'type' => 'import_adjustment',
                'amount' => $delta,
                'transaction_date' => $asOf,
                'reference_no' => "IMPORT-{$batch->id}",
                'remarks' => 'Masterlist balance ₱'.number_format((float) $data['savings_balance'], 2)." as of {$asOf->toDateString()}",
                'import_batch_id' => $batch->id,
            ]);
            $changed = true;
        } else {
            $member->refreshStatus();
        }

        $result = $isNew ? 'created' : ($changed ? 'updated' : 'unchanged');

        $row->update(['status' => 'imported', 'result' => $result, 'member_id' => $member->id]);

        return $result;
    }

    private function referenceField(string $kind): string
    {
        return match ($kind) {
            'flag' => 'savings_account_status',
            'terminal' => 'terminal_status',
            'segment' => 'segment',
            default => 'savings_balance',
        };
    }

    private function complete(ImportBatch $batch): void
    {
        if ($batch->status === 'completed') {
            return;
        }

        $batch->update(['status' => 'completed', 'completed_at' => now()]);
        $batch->recordAudit('imported', null, [
            'file' => $batch->file_name,
            'created' => $batch->created_count,
            'updated' => $batch->updated_count,
            'unchanged' => $batch->unchanged_count,
            'invalid' => $batch->invalid_rows,
            'duplicates' => $batch->duplicate_rows,
        ], 'Masterlist import completed');
    }

    public function cancel(ImportBatch $batch): void
    {
        $batch->update(['status' => 'cancelled']);
    }

    /**
     * What deleting this import would remove (shown in the confirmation before anything is deleted).
     *
     * @return array{members: int, adjustments: int}
     */
    public function importedDataSummary(ImportBatch $batch): array
    {
        $createdMemberIds = $this->createdMemberIds($batch);

        return [
            'members' => Member::withTrashed()->whereIn('id', $createdMemberIds)->count(),
            'adjustments' => SavingsTransaction::query()->where('import_batch_id', $batch->id)->whereNotIn('member_id', $createdMemberIds)->count(),
        ];
    }

    /**
     * Undo one imported file: permanently delete the members it added (their applications, savings entries,
     * notices and history go with them), take back the balance adjustments it made to members that already
     * existed, then remove the stored workbook and the import itself. Other details it updated on existing
     * members (name, segment, category) are not rolled back.
     *
     * @return array{members: int, adjustments: int}
     */
    public function deleteImportedData(ImportBatch $batch): array
    {
        set_time_limit(0);

        $summary = DB::transaction(function () use ($batch) {
            $createdMemberIds = $this->createdMemberIds($batch);
            $membersDeleted = 0;

            foreach (array_chunk($createdMemberIds, 1000) as $ids) {
                $membersDeleted += DB::table('members')->whereIn('id', $ids)->delete();
            }

            $adjustmentsReversed = 0;

            AuditLog::withoutRecording(function () use ($batch, &$adjustmentsReversed) {
                SavingsTransaction::query()
                    ->where('import_batch_id', $batch->id)
                    ->chunkById(500, function ($transactions) use (&$adjustmentsReversed) {
                        foreach ($transactions as $transaction) {
                            $transaction->delete();
                            $adjustmentsReversed++;
                        }
                    });
            });

            $summary = ['members' => $membersDeleted, 'adjustments' => $adjustmentsReversed];

            $batch->recordAudit('imported_data_deleted', ['file' => $batch->file_name, 'status' => $batch->status], $summary, 'Import and the data it added were deleted from Import History');
            $batch->delete();

            return $summary;
        });

        if ($batch->file_path) {
            Storage::disk(self::DISK)->delete($batch->file_path);
        }

        return $summary;
    }

    /**
     * @return list<int>
     */
    private function createdMemberIds(ImportBatch $batch): array
    {
        return ImportRow::query()
            ->where('import_batch_id', $batch->id)
            ->where('result', 'created')
            ->whereNotNull('member_id')
            ->distinct()
            ->pluck('member_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * CSV of every row that was not imported cleanly (invalid, duplicate, warnings, failures).
     */
    public function errorReport(ImportBatch $batch): StreamedResponse
    {
        return response()->streamDownload(function () use ($batch) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Sheet', 'Row', 'Acct. Number', 'Account Name', 'Classification', 'Result', 'Errors', 'Warnings']);

            ImportRow::query()
                ->where('import_batch_id', $batch->id)
                ->where(fn ($query) => $query
                    ->whereIn('action', ['invalid', 'duplicate', 'reference'])
                    ->orWhere('status', 'skipped')
                    ->orWhere(fn ($query) => $query->whereNotNull('warnings')->where('warnings', '<>', '[]')))
                ->orderBy('id')
                ->chunkById(1000, function ($rows) use ($out) {
                    foreach ($rows as $row) {
                        fputcsv($out, [
                            $row->sheet,
                            $row->row_number,
                            $row->account_no,
                            $row->account_name,
                            $row->action === 'reference' ? 'Side list' : (ImportRow::ACTIONS[$row->action] ?? $row->action),
                            $row->result ?? $row->status,
                            implode('; ', $row->errors ?? []),
                            implode('; ', $row->warnings ?? []),
                        ]);
                    }
                });

            fclose($out);
        }, 'import-'.$batch->id.'-issues.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
