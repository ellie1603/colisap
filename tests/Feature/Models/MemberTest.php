<?php

namespace Tests\Feature\Models;

use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\Member;
use App\Services\Policy\PolicySettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MemberTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_account_number_keeps_leading_zeros_and_must_be_unique(): void
    {
        Member::factory()->create(['account_no' => '00101959720']);

        $this->assertSame('00101959720', Member::sole()->account_no);

        $this->expectException(UniqueConstraintViolationException::class);
        Member::factory()->create(['account_no' => '00101959720']);
    }

    public function test_effectivity_date_and_days_remaining_are_calculated_from_approval(): void
    {
        $this->travelTo('2026-03-01 08:00:00');
        $member = Member::factory()->waiting()->create(['approval_date' => '2026-01-01']);

        $this->assertSame('2026-06-30', $member->effectivityDate()->toDateString());
        $this->assertSame(121, $member->daysUntilEffective());
    }

    public function test_status_category_segment_and_branch_changes_are_kept_in_history(): void
    {
        $member = Member::factory()->create(['category' => '40000', 'segment' => 'S', 'branch_id' => Branch::where('name', 'Barbaza')->value('id')]);

        $member->withChangeReason('Correction')->update([
            'category' => '60000',
            'segment' => 'G',
            'branch_id' => Branch::where('name', 'Kalibo')->value('id'),
        ]);
        $member->update(['category' => '40000']);

        $history = $member->histories()->get();

        $this->assertSame(['40000 → 60000', '60000 → 40000'], $history->where('field', 'category')->sortBy('id')->map(fn ($h) => "{$h->old_value} → {$h->new_value}")->values()->all());
        $this->assertSame('S → G', $history->firstWhere('field', 'segment')->old_value.' → '.$history->firstWhere('field', 'segment')->new_value);
        $this->assertSame(['Barbaza', 'Kalibo', 'Correction'], [$history->firstWhere('field', 'branch')->old_value, $history->firstWhere('field', 'branch')->new_value, $history->firstWhere('field', 'branch')->reason]);
    }

    public function test_eligibility_requires_active_status_minimum_balance_and_beneficiaries(): void
    {
        $member = Member::factory()->create(['savings_balance' => 500]);
        $this->assertSame(['Beneficiary information incomplete'], $member->eligibilityIssues());

        Beneficiary::factory()->for($member)->create(['share_percentage' => 100]);
        $this->assertTrue($member->fresh()->isEligible());

        $member->update(['savings_balance' => 499.99]);
        $this->assertFalse($member->fresh()->isEligible());
    }

    public function test_beneficiary_limits_follow_policy_settings(): void
    {
        $member = Member::factory()->create();
        Beneficiary::factory()->for($member)->count(4)->create(['share_percentage' => 25]);

        $this->assertFalse($member->hasCompleteBeneficiaries(), 'Four beneficiaries exceed the default maximum of 3.');

        app(PolicySettings::class)->update(['max_beneficiaries' => 4]);
        $this->assertTrue($member->fresh()->hasCompleteBeneficiaries());
    }

    public function test_only_active_forty_k_members_can_request_an_upgrade(): void
    {
        $member = Member::factory()->waiting()->create();

        $this->expectException(RuntimeException::class);
        $member->requestCategoryUpgrade();
    }

    public function test_upgrade_moves_member_to_sixty_k_and_clears_the_request(): void
    {
        $member = Member::factory()->create(['category_upgrade_requested_at' => now()->subDays(90), 'savings_balance' => 2500]);

        $this->assertTrue($member->applyCategoryUpgradeIfEligible());
        $this->assertSame(['60000', null], [$member->fresh()->category, $member->fresh()->category_upgrade_requested_at]);
        $this->assertStringContainsString('Policy III.3', $member->histories()->where('field', 'category')->value('reason'));
    }

    public function test_deleting_a_member_archives_instead_of_removing(): void
    {
        $member = Member::factory()->create();

        $member->delete();

        $this->assertSoftDeleted($member);
    }
}
