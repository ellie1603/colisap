<?php

namespace App\Services\Masterlist;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Services\Colisap\MemberStatusEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Updates the savings of members already in the system from a branch's savings list: one row per member with
 * the Acct. Number, the current savings balance and (optionally) the last transaction date. A changed balance is
 * recorded as a savings transaction, the last transaction date is kept, and each member is marked dormant in
 * transaction (dormant-txn) or in balance (dormant-bal) before their status is worked out again. Accounts that
 * are not members are skipped. Each upload is kept in Import History, where deleting it takes the balances back.
 */
class SavingsImportService
{
    private const CHUNK = 500;

    public function __construct(
        private WorkbookParser $parser,
        private MemberStatusEngine $engine,
    ) {}

    /**
     * @return array{updated: int, unchanged: int, not_found: int, other_branch: int, skipped: int, sheets: int}
     */
    public static function emptySummary(): array
    {
        return ['updated' => 0, 'unchanged' => 0, 'not_found' => 0, 'other_branch' => 0, 'skipped' => 0, 'sheets' => 0];
    }

    /**
     * The whole file at once (the page imports a large workbook sheet by sheet instead, see importSheet()).
     *
     * @return array{updated: int, unchanged: int, not_found: int, other_branch: int, skipped: int, sheets: int}
     */
    public function import(string $path, ImportBatch $batch): array
    {
        $summary = self::emptySummary();

        foreach ($this->sheets($path) as $sheet) {
            $this->importSheet($path, $sheet, $summary, $batch);
        }

        return $summary;
    }

    /**
     * The sheets with an Acct. Number (or CIF Key) column and a savings balance column.
     *
     * @return list<array{index: int, name: string, header_row: int, account_column: int, balance_column: int, last_transaction_column: ?int}>
     */
    public function sheets(string $path): array
    {
        $sheets = [];

        foreach ($this->parser->detectSheets($path) as $sheet) {
            $columns = $sheet['columns'];

            if ($sheet['header_row'] !== null && isset($columns['account_no'], $columns['savings_balance'])) {
                $sheets[] = [
                    'index' => $sheet['index'],
                    'name' => $sheet['name'],
                    'header_row' => $sheet['header_row'],
                    'account_column' => $columns['account_no'],
                    'balance_column' => $columns['savings_balance'],
                    'last_transaction_column' => $this->parser->lastTransactionColumn($sheet['headers']),
                ];
            }
        }

        return $sheets;
    }

    /**
     * @param  array{index: int, name: string, header_row: int, account_column: int, balance_column: int, last_transaction_column: ?int}  $sheet
     * @param  array{updated: int, unchanged: int, not_found: int, other_branch: int, skipped: int, sheets: int}  $summary
     */
    public function importSheet(string $path, array $sheet, array &$summary, ImportBatch $batch): void
    {
        $summary['sheets']++;
        $pending = [];

        foreach ($this->parser->rows($path, $sheet['index'], $sheet['header_row']) as $cells) {
            $accountNo = rtrim((string) $this->parser->normalizeAccountNumber($cells[$sheet['account_column']] ?? null), '.');
            $balance = $this->parser->parseAmount($cells[$sheet['balance_column']] ?? null);
            $lastTransaction = $sheet['last_transaction_column'] === null ? null : $this->parser->parseDate($cells[$sheet['last_transaction_column']] ?? null);

            if ($accountNo === '' && $balance === null) {
                continue;
            }

            if ($accountNo === '' || $balance === null) {
                $summary['skipped']++;

                continue;
            }

            $pending[$accountNo] = ['balance' => $balance, 'last_transaction' => $lastTransaction];

            if (count($pending) >= self::CHUNK) {
                $this->save($pending, $summary, $batch);
                $pending = [];
            }
        }

        $this->save($pending, $summary, $batch);
    }

    /**
     * @param  array<string, array{balance: float, last_transaction: ?string}>  $rows  keyed by Acct. Number
     * @param  array{updated: int, unchanged: int, not_found: int, other_branch: int, skipped: int, sheets: int}  $summary
     */
    private function save(array $rows, array &$summary, ImportBatch $batch): void
    {
        if ($rows === []) {
            return;
        }

        $accountNumbers = array_map('strval', array_keys($rows));
        // Staff limited to one branch only update their branch's members (the members scope sees no others).
        $members = Member::query()->whereIn('account_no', $accountNumbers)->get()->keyBy('account_no');
        $elsewhere = Branch::restrictedId() === null
            ? collect()
            : Branch::withoutRestriction(fn () => Member::withTrashed()->whereIn('account_no', $accountNumbers)->pluck('account_no'))->flip();

        // No audit entry per member: the upload, its savings transactions and the member history are the trail.
        AuditLog::withoutRecording(function () use ($rows, $members, $elsewhere, &$summary, $batch) {
            foreach ($rows as $accountNo => $row) {
                $member = $members->get((string) $accountNo);

                if ($member === null) {
                    $elsewhere->has((string) $accountNo) ? $summary['other_branch']++ : $summary['not_found']++;

                    continue;
                }

                $result = DB::transaction(fn () => $this->applySavings($member, $row['balance'], $row['last_transaction'] ? Carbon::parse($row['last_transaction']) : null, $batch));
                $summary[$result]++;
            }
        });
    }

    /**
     * Bring one member's savings up to date with the list.
     *
     * @return 'updated'|'unchanged'
     */
    public function applySavings(Member $member, float $balance, ?Carbon $lastTransaction, ImportBatch $batch): string
    {
        $delta = round($balance - (float) $member->savings_balance, 2);
        $balanceChanged = $member->savings_balance === null || abs($delta) >= 0.01;

        $member->savings_balance = $balance;
        $member->savings_balance_as_of = $batch->as_of_date ?? Carbon::today();

        if ($lastTransaction !== null && ($member->last_activity_date === null || $lastTransaction->gt($member->last_activity_date))) {
            $member->last_activity_date = $lastTransaction;
        }

        $member->savings_account_status = $this->engine->savingsDormancyFlag($balance, $member->last_activity_date);
        $changed = $balanceChanged || $member->isDirty(['last_activity_date', 'savings_account_status']);

        if ($balanceChanged && abs($delta) >= 0.01) {
            // Saved quietly: the balance is set on the member here, so the transaction must not move it again.
            // Deleting the upload deletes these transactions, which takes the balance back.
            $transaction = new SavingsTransaction([
                'member_id' => $member->id,
                'type' => 'import_adjustment',
                'amount' => $delta,
                'balance_after' => $balance,
                'transaction_date' => $lastTransaction ?? $member->savings_balance_as_of,
                'reference_no' => "SAVINGS-{$batch->id}",
                'remarks' => 'Savings list balance ₱'.number_format($balance, 2),
                'import_batch_id' => $batch->id,
                'recorded_by' => Auth::id(),
            ]);
            $transaction->saveQuietly();
        }

        $member->withChangeReason("Savings import #{$batch->id}", 'import')->save();
        $member->refreshStatus();

        return $changed ? 'updated' : 'unchanged';
    }

    /**
     * Start the Import History entry for an uploaded savings list.
     *
     * @param  list<array{name: string}>  $sheets
     */
    public function startBatch(string $fileName, array $sheets): ImportBatch
    {
        return ImportBatch::create([
            'type' => 'savings',
            'file_name' => $fileName,
            'as_of_date' => now(),
            'status' => 'importing',
            'sheets' => array_map(fn (array $sheet) => ['name' => $sheet['name'], 'include' => true], $sheets),
            'uploaded_by' => Auth::id(),
        ]);
    }

    /**
     * @param  array{updated: int, unchanged: int, not_found: int, other_branch: int, skipped: int, sheets: int}  $summary
     */
    public function finishBatch(ImportBatch $batch, array $summary, ?string $errorMessage = null): void
    {
        $batch->update([
            'status' => $errorMessage === null ? 'completed' : 'failed',
            'total_rows' => array_sum(array_intersect_key($summary, array_flip(['updated', 'unchanged', 'not_found', 'other_branch', 'skipped']))),
            'update_rows' => $summary['updated'],
            'invalid_rows' => $summary['not_found'] + $summary['other_branch'] + $summary['skipped'],
            'processed_rows' => $summary['updated'] + $summary['unchanged'],
            'updated_count' => $summary['updated'],
            'unchanged_count' => $summary['unchanged'],
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    /**
     * How many balance changes deleting this upload would take back.
     */
    public function importedDataCount(ImportBatch $batch): int
    {
        return SavingsTransaction::query()->where('import_batch_id', $batch->id)->count();
    }

    /**
     * Undo one savings upload: delete the transactions it recorded (each deletion takes its change back off the
     * balance), then remove the upload from Import History. Last transaction dates it filled in are kept.
     */
    public function deleteImportedData(ImportBatch $batch): int
    {
        return DB::transaction(function () use ($batch) {
            $reversed = 0;

            AuditLog::withoutRecording(function () use ($batch, &$reversed) {
                SavingsTransaction::query()->where('import_batch_id', $batch->id)->chunkById(500, function ($transactions) use (&$reversed) {
                    foreach ($transactions as $transaction) {
                        $transaction->delete();
                        $reversed++;
                    }
                });
            });

            $batch->recordAudit('imported_data_deleted', ['file' => $batch->file_name, 'status' => $batch->status], ['balance_changes' => $reversed], 'Savings upload deleted from Import History and its balance changes taken back');
            $batch->delete();

            return $reversed;
        });
    }
}
