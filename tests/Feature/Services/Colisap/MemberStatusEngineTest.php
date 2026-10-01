<?php

namespace Tests\Feature\Services\Colisap;

use App\Models\Member;
use App\Models\ReplenishmentNotice;
use App\Services\Colisap\MemberStatusEngine;
use App\Services\Policy\PolicySettings;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MemberStatusEngineTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-30 08:00:00');
    }

    private function evaluate(Member $member): Member
    {
        app(MemberStatusEngine::class)->evaluate($member);

        return $member->fresh();
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function waitingPeriodBoundaries(): array
    {
        return [
            '179 days after approval' => [179, 'waiting'],
            'exactly 180 days' => [180, 'active'],
            '181 days after approval' => [181, 'active'],
        ];
    }

    #[DataProvider('waitingPeriodBoundaries')]
    public function test_member_becomes_active_on_the_180th_day_after_approval(int $daysSinceApproval, string $expected): void
    {
        $member = Member::factory()->waiting()->create(['approval_date' => now()->subDays($daysSinceApproval)]);

        $this->assertSame($expected, $this->evaluate($member)->status);
    }

    public function test_activation_records_effectivity_date_and_history_reason(): void
    {
        $member = Member::factory()->waiting()->create(['approval_date' => '2026-01-01']);

        $member = $this->evaluate($member);

        $this->assertSame('2026-06-30', $member->activated_at->toDateString());
        $this->assertStringContainsString('Policy III.1', $member->histories()->where('field', 'status')->first()->reason);
    }

    public function test_member_without_approval_date_stays_waiting(): void
    {
        $member = Member::factory()->create(['approval_date' => null, 'status' => 'active']);

        $this->assertSame('waiting', $this->evaluate($member)->status);
    }

    public function test_masterlist_dormancy_flag_makes_effective_member_dormant(): void
    {
        $member = Member::factory()->create(['savings_account_status' => 'dormant-bal', 'savings_balance_as_of' => '2026-06-01']);

        $member = $this->evaluate($member);

        $this->assertSame(['dormant', '2026-06-01'], [$member->status, $member->dormant_since->toDateString()]);
    }

    public function test_member_is_terminated_after_three_consecutive_dormant_months(): void
    {
        $member = Member::factory()->create(['savings_account_status' => 'dormant-txn', 'dormant_since' => '2026-03-30']);

        $member = $this->evaluate($member);

        $this->assertSame('terminated', $member->status);
        $this->assertSame('2026-06-30', $member->terminated_at->toDateString());
        $this->assertStringContainsString('Policy VII.2', $member->termination_reason);
    }

    public function test_dormant_member_one_day_short_of_three_months_is_not_terminated(): void
    {
        $member = Member::factory()->create(['savings_account_status' => 'dormant-txn', 'dormant_since' => '2026-04-01']);

        $this->assertSame('dormant', $this->evaluate($member)->status);
    }

    public function test_clearing_dormancy_flag_reactivates_member(): void
    {
        $member = Member::factory()->dormant()->create(['savings_account_status' => null]);

        $member = $this->evaluate($member);

        $this->assertSame('active', $member->status);
        $this->assertNull($member->dormant_since);
    }

    public function test_recorded_activity_dormancy_is_used_when_configured(): void
    {
        app(PolicySettings::class)->update(['dormancy_activity_source' => 'recorded_activity']);
        $inactive = Member::factory()->create(['last_activity_date' => '2026-03-30']);
        $recent = Member::factory()->create(['last_activity_date' => '2026-04-01']);
        $unknown = Member::factory()->create(['last_activity_date' => null]);

        $this->assertSame('dormant', $this->evaluate($inactive)->status);
        $this->assertSame('active', $this->evaluate($recent)->status);
        $this->assertSame('active', $this->evaluate($unknown)->status, 'Unknown activity never counts as dormant.');
    }

    /**
     * @return array<string, array{0: string, 1: float, 2: bool}>
     */
    public static function maintainingBalances(): array
    {
        return [
            '40K exactly ₱500' => ['40000', 500.00, false],
            '40K below ₱500' => ['40000', 499.99, true],
            '60K exactly ₱2,000' => ['60000', 2000.00, false],
            '60K below ₱2,000' => ['60000', 1999.99, true],
        ];
    }

    #[DataProvider('maintainingBalances')]
    public function test_replenishment_notice_is_issued_only_below_the_maintaining_balance(string $category, float $balance, bool $expectsNotice): void
    {
        $member = Member::factory()->create(['category' => $category, 'savings_balance' => $balance]);

        $this->evaluate($member);

        $this->assertSame($expectsNotice, $member->replenishmentNotices()->where('status', 'open')->exists());
    }

    public function test_notice_records_shortfall_and_fifteen_day_deadline(): void
    {
        $member = Member::factory()->create(['savings_balance' => 320]);

        $this->evaluate($member);

        $notice = $member->replenishmentNotices()->sole();
        $this->assertSame(['500.00', '320.00', '180.00', '2026-07-15'], [$notice->required_balance, $notice->balance_at_notice, $notice->shortfall, $notice->deadline->toDateString()]);
    }

    public function test_replenishing_within_the_period_resolves_the_notice(): void
    {
        $member = Member::factory()->create(['savings_balance' => 300]);
        $this->evaluate($member);

        $member->update(['savings_balance' => 800]);
        $this->travel(10)->days();
        $member = $this->evaluate($member);

        $this->assertSame('active', $member->status);
        $this->assertSame('replenished', $member->replenishmentNotices()->sole()->status);
    }

    public function test_forty_k_member_is_terminated_when_notice_expires_without_replenishment(): void
    {
        $member = Member::factory()->create(['savings_balance' => 300]);
        $this->evaluate($member);

        $this->travelTo('2026-07-15 08:00:00');
        $this->assertSame('active', $this->evaluate($member)->status, 'Still within the deadline day.');

        $this->travelTo('2026-07-16 08:00:00');
        $member = $this->evaluate($member);

        $this->assertSame('terminated', $member->status);
        $this->assertStringContainsString('Policy VII.1', $member->termination_reason);
        $this->assertSame('terminated', $member->replenishmentNotices()->sole()->status);
    }

    public function test_sixty_k_member_is_downgraded_to_forty_k_when_notice_expires(): void
    {
        $member = Member::factory()->category60000()->create(['savings_balance' => 1500]);
        $this->evaluate($member);

        $this->travelTo('2026-07-16 08:00:00');
        $member = $this->evaluate($member);

        $this->assertSame(['active', '40000'], [$member->status, $member->category]);
        $this->assertSame('downgraded', $member->replenishmentNotices()->oldest('id')->first()->status);
        $this->assertSame('60000', $member->histories()->where('field', 'category')->first()->old_value);
        $this->assertStringContainsString('Policy VII.3', $member->histories()->where('field', 'category')->first()->reason);
    }

    public function test_automatic_enforcement_can_be_switched_off(): void
    {
        app(PolicySettings::class)->update(['auto_enforce_terminations' => false]);
        $member = Member::factory()->create(['savings_balance' => 300]);
        $this->evaluate($member);

        $this->travelTo('2026-08-30 08:00:00');

        $this->assertSame('active', $this->evaluate($member)->status);
    }

    public function test_terminal_statuses_are_never_changed_and_open_notices_are_cancelled(): void
    {
        foreach (['deceased', 'terminated', 'withdrawn'] as $state) {
            $member = Member::factory()->{$state}()->create(['savings_balance' => 0]);
            ReplenishmentNotice::factory()->for($member)->create();

            $member = $this->evaluate($member);

            $this->assertSame($state, $member->status);
            $this->assertSame('cancelled', $member->replenishmentNotices()->sole()->status);
        }
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function upgradeBoundaries(): array
    {
        return [
            '89 days after request' => [89, 'pending'],
            'exactly 90 days' => [90, 'eligible'],
        ];
    }

    #[DataProvider('upgradeBoundaries')]
    public function test_upgrade_eligibility_starts_ninety_days_after_request(int $days, string $expected): void
    {
        $member = Member::factory()->create(['category_upgrade_requested_at' => now()->subDays($days)]);

        $this->assertSame($expected, $member->upgradeStatus());
    }

    public function test_eligible_upgrade_is_applied_automatically_only_when_enabled_and_balance_meets_sixty_k_minimum(): void
    {
        $rich = Member::factory()->create(['category_upgrade_requested_at' => now()->subDays(90), 'savings_balance' => 2000]);
        $poor = Member::factory()->create(['category_upgrade_requested_at' => now()->subDays(90), 'savings_balance' => 1999]);

        $this->assertSame('40000', $this->evaluate($rich)->category, 'Automatic upgrades are off by default.');

        app(PolicySettings::class)->update(['auto_apply_upgrades' => true]);

        $this->assertSame('60000', $this->evaluate($rich)->category);
        $this->assertSame('40000', $this->evaluate($poor)->category);
    }

    public function test_policy_changes_take_effect_immediately(): void
    {
        app(PolicySettings::class)->update(['effectivity_days' => 30]);
        $member = Member::factory()->waiting()->create(['approval_date' => now()->subDays(30)]);

        $this->assertSame('active', $this->evaluate($member)->status);
    }
}
