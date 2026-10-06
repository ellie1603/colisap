<?php

namespace Tests\Feature\Services\Sms;

use App\Models\Branch;
use App\Models\Member;
use App\Models\ReplenishmentNotice;
use App\Services\Sms\MonitoringSms;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MonitoringSmsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_pending_upgrade_request_is_told_when_it_becomes_eligible(): void
    {
        $member = Member::factory()->create(['first_name' => 'Pedro', 'category_upgrade_requested_at' => '2026-09-01']);

        $this->assertSame(
            'Good day, Pedro! Your request to upgrade your COLISAP coverage to the 60K category will be eligible on Nov 30, 2026. Please keep at least P2,000 in your savings by then. - Barbaza MPC',
            app(MonitoringSms::class)->message('upgrades', $member),
        );
    }

    public function test_a_dormant_member_is_told_the_date_their_participation_ends(): void
    {
        $this->travelTo('2026-07-01 09:00:00');
        $member = Member::factory()->dormant()->create([
            'first_name' => 'LONESEL',
            'dormant_since' => '2026-06-15',
            'branch_id' => Branch::where('name', 'Balasan')->value('id'),
        ]);

        $this->assertSame(
            'Good day, Lonesel! Your savings account is dormant. Please make a deposit at your Barbaza MPC branch (Balasan) on or before Sep 15, 2026 to keep your COLISAP coverage. - Barbaza MPC',
            app(MonitoringSms::class)->message('dormancy', $member),
        );

        $this->travelTo('2026-10-05 09:00:00');

        $this->assertStringContainsString('Please make a deposit at your Barbaza MPC branch (Balasan) as soon as possible', app(MonitoringSms::class)->message('dormancy', $member));
    }

    public function test_an_overdue_replenishment_notice_asks_for_an_immediate_deposit(): void
    {
        $this->travelTo('2026-10-05 09:00:00');
        $notice = ReplenishmentNotice::factory()->create([
            'member_id' => Member::factory()->create(['first_name' => 'Ana', 'savings_balance' => 120.5]),
            'deadline' => '2026-10-01',
        ]);

        $this->assertSame(
            'Good day, Ana! Your savings of P120.50 is below the P500 COLISAP maintaining balance. Please deposit at least P379.50 immediately (it was due on Oct 1, 2026) to keep your coverage. - Barbaza MPC',
            app(MonitoringSms::class)->message('replenishment', $notice),
        );
    }

    public function test_members_with_nothing_to_be_reminded_of_get_no_message(): void
    {
        $sms = app(MonitoringSms::class);
        $resolved = ReplenishmentNotice::factory()->create(['status' => 'replenished']);

        $this->assertNull($sms->message('effectivity', Member::factory()->waiting()->create(['approval_date' => null])));
        $this->assertNull($sms->message('upgrades', Member::factory()->create(['category_upgrade_requested_at' => null])));
        $this->assertNull($sms->message('replenishment', $resolved));
    }
}
