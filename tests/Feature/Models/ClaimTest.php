<?php

namespace Tests\Feature\Models;

use App\Models\Beneficiary;
use App\Models\Claim;
use App\Models\Member;
use App\Models\User;
use App\Services\Policy\PolicySettings;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ClaimTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_filing_a_claim_marks_member_deceased_on_the_date_of_death(): void
    {
        $member = Member::factory()->create();

        Claim::factory()->for($member)->create(['date_of_death' => '2026-05-20']);

        $member->refresh();
        $this->assertSame(['deceased', '2026-05-20'], [$member->status, $member->date_deceased->toDateString()]);
    }

    public function test_gross_benefit_follows_the_members_category_and_policy(): void
    {
        $forty = Claim::factory()->for(Member::factory())->create();
        $sixty = Claim::factory()->for(Member::factory()->category60000())->create();

        $this->assertSame(['40000.00', '60000.00'], [$forty->gross_benefit, $sixty->gross_benefit]);

        app(PolicySettings::class)->update(['benefit_60k' => 65000]);
        $this->assertSame('65000.00', Claim::factory()->for(Member::factory()->category60000())->create()->gross_benefit);
    }

    public function test_deductions_reduce_net_benefit_without_changing_the_gross(): void
    {
        $claim = Claim::factory()->create();

        $claim->deductions()->create(['type' => 'loan', 'description' => 'Regular loan', 'amount' => 12500]);
        $claim->deductions()->create(['type' => 'obligation', 'description' => 'Share capital deficiency', 'amount' => 500]);

        $claim->refresh();
        $this->assertSame(['40000.00', '12500.00', '500.00', '27000.00'], [$claim->gross_benefit, $claim->outstanding_loan, $claim->other_obligations, $claim->net_benefit]);
    }

    public function test_net_benefit_never_goes_negative(): void
    {
        $claim = Claim::factory()->create();

        $claim->deductions()->create(['type' => 'loan', 'description' => 'Loan', 'amount' => 50000]);

        $this->assertSame('0.00', $claim->fresh()->net_benefit);
    }

    public function test_approval_splits_net_benefit_by_beneficiary_share(): void
    {
        $member = Member::factory()->create();
        $spouse = Beneficiary::factory()->for($member)->create(['share_percentage' => 50]);
        Beneficiary::factory()->for($member)->create(['share_percentage' => 30]);
        Beneficiary::factory()->for($member)->create(['share_percentage' => 20]);
        $claim = Claim::factory()->verified()->for($member)->create(['beneficiary_id' => $spouse->id, 'status' => 'under_review']);
        $claim->deductions()->create(['type' => 'loan', 'description' => 'Loan', 'amount' => 10000]);

        $claim->fresh()->approve(User::factory()->create());

        $this->assertSame(['15000.00', '9000.00', '6000.00'], $claim->payouts()->orderByDesc('amount')->pluck('amount')->all());
    }

    public function test_settlement_releases_every_payout(): void
    {
        $claim = Claim::factory()->verified()->create(['status' => 'under_review']);
        $user = User::factory()->create();
        $claim->approve($user);

        $claim->settle($user, 'CV-001');

        $this->assertSame('settled', $claim->status);
        $this->assertTrue($claim->payouts()->whereNull('released_at')->doesntExist());
    }

    public function test_death_during_waiting_period_is_flagged(): void
    {
        $member = Member::factory()->waiting()->create(['approval_date' => now()->subDays(30)]);

        $claim = Claim::factory()->for($member)->create(['date_of_death' => now()->subDay()]);

        $this->assertStringContainsString('waiting period', $claim->eligibilityIssues()[0]);
    }
}
