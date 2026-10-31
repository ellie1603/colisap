<?php

namespace Tests\Feature\Services\Masterlist;

use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Masterlist\SavingsImportService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesMasterlistWorkbooks;
use Tests\TestCase;

/**
 * The savings import on Member Transactions: a savings list updates members already in the system and marks
 * them dormant in transaction (no transaction for 3 months) or in balance (below ₱2,000).
 */
class SavingsImportServiceTest extends TestCase
{
    use CreatesMasterlistWorkbooks, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-22 08:00:00');
        Storage::fake('local');
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function import(array $rows): array
    {
        $path = Storage::disk('local')->path('savings.xlsx');
        $this->writeMasterlistWorkbook($path, ['Savings' => [['CIF Key', 'Savings Balance', 'Last Transaction Date'], ...$rows]]);

        $service = app(SavingsImportService::class);
        $batch = $service->startBatch('savings.xlsx', $service->sheets($path));
        $summary = $service->import($path, $batch);
        $service->finishBatch($batch, $summary);

        return [$summary, $batch->refresh()];
    }

    public function test_balances_and_last_transaction_dates_are_recorded_and_each_member_marked_dormant_or_not(): void
    {
        $active = Member::factory()->create(['account_no' => '00101000001', 'savings_balance' => 1000, 'last_activity_date' => null]);
        $inactive = Member::factory()->create(['account_no' => '00101000002', 'savings_balance' => 5000]);
        $lowBalance = Member::factory()->create(['account_no' => '00101000003', 'savings_balance' => 5000]);

        [$summary, $batch] = $this->import([
            ['00101000001', 2500, '2026-09-01'],
            ['00101000002', 5000, '2026-05-01'],
            ['00101000003', 800, '2026-09-10'],
            ['00999999999', 100, '2026-09-10'],
            ['00101000004', '', '2026-09-10'],
        ]);

        $this->assertSame(['updated' => 3, 'unchanged' => 0, 'not_found' => 1, 'other_branch' => 0, 'skipped' => 1, 'sheets' => 1], $summary);
        $this->assertSame(['savings', 'completed', 3], [$batch->type, $batch->status, $batch->updated_count]);

        $active->refresh();
        $this->assertSame(['2500.00', '2026-09-01', null, 'active'], [$active->savings_balance, $active->last_activity_date->toDateString(), $active->savings_account_status, $active->status]);
        $this->assertSame(['1500.00', '2026-09-01'], [
            SavingsTransaction::where('member_id', $active->id)->sole()->amount,
            SavingsTransaction::where('member_id', $active->id)->sole()->transaction_date->toDateString(),
        ], 'The change is recorded as a savings transaction on the last transaction date.');

        $this->assertSame(['dormant-txn', 'dormant'], [$inactive->fresh()->savings_account_status, $inactive->fresh()->status], 'No transaction since May: dormant in transaction.');
        $this->assertFalse(SavingsTransaction::where('member_id', $inactive->id)->exists(), 'An unchanged balance records no transaction.');
        $this->assertSame(['dormant-bal', 'dormant'], [$lowBalance->fresh()->savings_account_status, $lowBalance->fresh()->status], 'Below ₱2,000: dormant in balance.');
    }

    public function test_deleting_the_upload_takes_the_balance_changes_back(): void
    {
        $member = Member::factory()->create(['account_no' => '00101000001', 'savings_balance' => 1000]);
        [, $batch] = $this->import([['00101000001', 2500, '2026-09-01']]);

        $reversed = app(SavingsImportService::class)->deleteImportedData($batch);

        $this->assertSame(1, $reversed);
        $this->assertSame('1000.00', $member->fresh()->savings_balance);
        $this->assertSame(0, ImportBatch::count());
    }

    public function test_staff_limited_to_one_branch_only_update_their_branchs_members(): void
    {
        $kalibo = Member::factory()->create(['account_no' => '00901000001', 'savings_balance' => 1000, 'branch_id' => Branch::where('name', 'Kalibo')->value('id')]);
        $this->actingAs(User::factory()->create(['is_active' => true, 'branch_id' => Branch::where('name', 'Barbaza')->value('id')])->assignRole('crs'));

        [$summary] = $this->import([['00901000001', 2500, '2026-09-01']]);

        $this->assertSame([0, 1], [$summary['updated'], $summary['other_branch']]);
        $this->assertSame('1000.00', $kalibo->fresh()->savings_balance);
    }
}
