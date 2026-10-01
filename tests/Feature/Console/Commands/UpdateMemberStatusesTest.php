<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Member;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UpdateMemberStatusesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_applies_policy_to_every_participating_member(): void
    {
        $this->travelTo('2026-06-30 08:00:00');
        $dueForActivation = Member::factory()->waiting()->create(['approval_date' => '2026-01-01']);
        $belowMinimum = Member::factory()->create(['savings_balance' => 100]);

        $this->artisan('colisap:update-member-statuses')
            ->expectsOutputToContain('Evaluated 2 member(s)')
            ->assertSuccessful();

        $this->assertSame('active', $dueForActivation->fresh()->status);
        $this->assertTrue($belowMinimum->replenishmentNotices()->where('status', 'open')->exists());
    }

    public function test_command_leaves_deceased_terminated_and_withdrawn_members_untouched(): void
    {
        $members = collect(['deceased', 'terminated', 'withdrawn'])->mapWithKeys(fn (string $state) => [$state => Member::factory()->{$state}()->create()]);

        $this->artisan('colisap:update-member-statuses')->assertSuccessful();

        $members->each(fn (Member $member, string $state) => $this->assertSame($state, $member->fresh()->status));
    }

    public function test_command_is_scheduled_nightly(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('colisap:update-member-statuses')->assertSuccessful();
    }
}
