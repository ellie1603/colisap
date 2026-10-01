<?php

namespace Tests\Feature\Services\Colisap;

use App\Models\Claim;
use App\Models\Contribution;
use App\Models\Member;
use App\Services\Colisap\ContributionService;
use App\Services\Policy\PolicySettings;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ContributionServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function approvedClaim(string $category = '40000'): Claim
    {
        $deceased = Member::factory()->create(['category' => $category]);

        return Claim::factory()->verified()->for($deceased)->create(['status' => 'approved']);
    }

    public function test_coop_shoulders_diamond_and_gold_share_and_members_pay_the_rest_from_savings(): void
    {
        $claim = $this->approvedClaim();
        $diamond = Member::factory()->segment('D')->create(['savings_balance' => 1000]);
        $gold = Member::factory()->segment('G')->create(['savings_balance' => 1000]);
        $regular = Member::factory()->segment('R')->create(['savings_balance' => 1000]);
        $unassigned = Member::factory()->segment(null)->create(['savings_balance' => 1000]);

        $summary = app(ContributionService::class)->generateForClaim($claim);

        $shares = Contribution::where('claim_id', $claim->id)->get()->keyBy('member_id');
        $this->assertSame(['0.00', '5.00', 'waived'], [$shares[$diamond->id]->member_share, $shares[$diamond->id]->coop_share, $shares[$diamond->id]->status]);
        $this->assertSame(['2.50', '2.50'], [$shares[$gold->id]->member_share, $shares[$gold->id]->coop_share]);
        $this->assertSame(['5.00', '0.00'], [$shares[$regular->id]->member_share, $shares[$regular->id]->coop_share]);
        $this->assertSame('5.00', $shares[$unassigned->id]->member_share);
        $this->assertSame(['1000.00', '997.50', '995.00'], [$diamond->fresh()->savings_balance, $gold->fresh()->savings_balance, $regular->fresh()->savings_balance]);
        $this->assertSame(['participants' => 4, 'coop_total' => 7.5], array_intersect_key($summary, ['participants' => 0, 'coop_total' => 0]));
    }

    public function test_only_active_participants_other_than_the_deceased_contribute(): void
    {
        $claim = $this->approvedClaim();
        Member::factory()->waiting()->create();
        Member::factory()->dormant()->create();
        $active = Member::factory()->create();

        app(ContributionService::class)->generateForClaim($claim);

        $this->assertSame([$active->id], Contribution::where('claim_id', $claim->id)->pluck('member_id')->all());
    }

    public function test_deduction_below_maintaining_balance_issues_replenishment_notice(): void
    {
        $claim = $this->approvedClaim();
        $member = Member::factory()->segment('R')->create(['savings_balance' => 503]);

        $summary = app(ContributionService::class)->generateForClaim($claim);

        $this->assertSame(1, $summary['notices']);
        $this->assertSame('498.00', $member->fresh()->savings_balance);
        $this->assertTrue($member->replenishmentNotices()->where('status', 'open')->exists());
    }

    public function test_equal_share_rule_divides_benefit_among_participants_when_enabled_and_below_threshold(): void
    {
        app(PolicySettings::class)->update(['equal_share_enabled' => true, 'equal_share_threshold' => 3000]);
        $claim = $this->approvedClaim();
        Member::factory()->count(4)->segment('R')->create(['savings_balance' => 50000]);

        app(ContributionService::class)->generateForClaim($claim);

        $this->assertSame(['10000.00'], Contribution::where('claim_id', $claim->id)->distinct()->pluck('amount')->all());
    }

    public function test_contributions_cannot_be_generated_twice_or_for_unapproved_claims(): void
    {
        $claim = $this->approvedClaim();
        Member::factory()->create();
        app(ContributionService::class)->generateForClaim($claim);

        $this->expectException(RuntimeException::class);
        app(ContributionService::class)->generateForClaim($claim->fresh());
    }

    public function test_submitted_claim_is_rejected(): void
    {
        $claim = Claim::factory()->create();

        $this->expectException(RuntimeException::class);
        app(ContributionService::class)->generateForClaim($claim);
    }
}
