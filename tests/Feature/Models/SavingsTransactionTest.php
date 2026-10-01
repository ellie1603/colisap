<?php

namespace Tests\Feature\Models;

use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Services\Policy\PolicySettings;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SavingsTransactionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_transactions_keep_the_member_balance_in_sync(): void
    {
        $member = Member::factory()->create(['savings_balance' => 1000]);

        $deposit = SavingsTransaction::factory()->for($member)->create(['amount' => 250]);
        SavingsTransaction::factory()->for($member)->withdrawal(100)->create();

        $this->assertSame('1150.00', $member->fresh()->savings_balance);
        $this->assertSame('1250.00', $deposit->fresh()->balance_after);

        $deposit->delete();
        $this->assertSame('900.00', $member->fresh()->savings_balance);
    }

    public function test_withdrawal_below_minimum_issues_replenishment_notice_immediately(): void
    {
        $member = Member::factory()->create(['savings_balance' => 600]);

        SavingsTransaction::factory()->for($member)->withdrawal(200)->create();

        $this->assertTrue($member->replenishmentNotices()->where('status', 'open')->exists());
    }

    public function test_deposits_and_withdrawals_count_as_activity_but_import_adjustments_do_not(): void
    {
        $member = Member::factory()->create(['last_activity_date' => '2026-01-01']);

        SavingsTransaction::factory()->for($member)->create(['type' => 'import_adjustment', 'amount' => 10, 'transaction_date' => '2026-05-01']);
        $this->assertSame('2026-01-01', $member->fresh()->last_activity_date->toDateString());

        SavingsTransaction::factory()->for($member)->create(['transaction_date' => '2026-05-02']);
        $this->assertSame('2026-05-02', $member->fresh()->last_activity_date->toDateString());
    }

    public function test_deposit_reactivates_member_dormant_by_recorded_inactivity(): void
    {
        $this->travelTo('2026-06-30 08:00:00');
        app(PolicySettings::class)->update(['dormancy_activity_source' => 'recorded_activity']);
        $member = Member::factory()->create(['last_activity_date' => '2026-02-01']);
        $member->refreshStatus();
        $this->assertSame('dormant', $member->fresh()->status);

        SavingsTransaction::factory()->for($member)->create(['transaction_date' => now()]);

        $this->assertSame('active', $member->fresh()->status);
    }
}
