<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Monitoring;
use App\Models\Branch;
use App\Models\Member;
use App\Models\ReplenishmentNotice;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class MonitoringSmsTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Monitoring and SMS are the Administrator's by default; an Administrator can grant them to CRS Officers.
        $this->grantToRole(Permissions::CRS, 'monitoring.view', 'monitoring.send_sms');
        Http::preventStrayRequests();
        config(['services.etxtmo' => ['url' => 'https://sms.test/api/v1', 'key' => 'txm_test_key']]);
    }

    private function fakeGatewayAcceptingMessages(): void
    {
        Http::fake(['sms.test/*' => Http::response(['message_id' => 'msg-1', 'status' => 'queued'], 201)]);
    }

    public function test_one_member_is_texted_the_60k_upgrade_reminder_from_the_upgrades_view(): void
    {
        $this->fakeGatewayAcceptingMessages();
        $member = Member::factory()->create([
            'first_name' => 'MARIA',
            'contact_number' => '+63 917 123 4567',
            'branch_id' => Branch::where('name', 'Sibalom')->value('id'),
            'category_upgrade_requested_at' => now()->subDays(90),
            'savings_balance' => 3000,
        ]);
        $this->actingAsRole(Permissions::CRS);
        $reminder = 'Good day, Maria! You are now eligible to upgrade your COLISAP coverage to the 60K category. '
            .'Please visit your Barbaza MPC branch (Sibalom) to complete it. Required savings: P2,000. - Barbaza MPC';

        Livewire::withQueryParams(['tab' => 'upgrades'])
            ->test(Monitoring::class)
            ->mountTableAction('sendSms', $member)
            ->assertTableActionDataSet(['message' => $reminder])
            ->callMountedTableAction()
            ->assertNotified('1 SMS queued for sending');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://sms.test/api/v1/sms/send'
            && $request->hasHeader('X-API-Key', 'txm_test_key')
            && $request->data() === ['to' => '09171234567', 'message' => $reminder]);
        $this->assertSame(
            ['to' => '09171234567', 'view' => 'upgrades', 'message' => $reminder, 'message_id' => 'msg-1'],
            $member->auditLogs()->where('action', 'sms_sent')->sole()->new_values,
        );
    }

    public function test_the_can_upgrade_view_lists_active_40k_members_with_the_60k_balance_and_invites_them_by_sms(): void
    {
        $this->fakeGatewayAcceptingMessages();
        $qualified = Member::factory()->create([
            'first_name' => 'Maria', 'contact_number' => '09171234567', 'savings_balance' => 2000,
            'branch_id' => Branch::where('name', 'Sibalom')->value('id'),
        ]);
        $belowTheMinimum = Member::factory()->create(['savings_balance' => 1999.99]);
        $already60k = Member::factory()->category60000()->create(['savings_balance' => 5000]);
        $alreadyRequested = Member::factory()->create(['savings_balance' => 5000, 'category_upgrade_requested_at' => now()->subDays(10)]);
        $stillWaiting = Member::factory()->waiting()->create(['savings_balance' => 5000]);
        $this->actingAsRole(Permissions::CRS);

        Livewire::withQueryParams(['tab' => 'qualified'])
            ->test(Monitoring::class)
            ->assertCanSeeTableRecords([$qualified])
            ->assertCanNotSeeTableRecords([$belowTheMinimum, $already60k, $alreadyRequested, $stillWaiting])
            ->assertTableActionHidden('requestUpgrade', $qualified)
            ->callTableAction('sendSms', $qualified)
            ->assertNotified('1 SMS queued for sending');

        Http::assertSent(fn (Request $request) => $request->data() === [
            'to' => '09171234567',
            'message' => 'Good day, Maria! Your savings of P2,000 qualifies you to upgrade your COLISAP coverage from 40K to the 60K category. Please visit your Barbaza MPC branch (Sibalom) to apply. - Barbaza MPC',
        ]);
    }

    public function test_recording_an_upgrade_request_moves_the_member_from_can_upgrade_to_the_90_day_view(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $member = Member::factory()->create(['savings_balance' => 3000]);
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::withQueryParams(['tab' => 'qualified'])
            ->test(Monitoring::class)
            ->callTableAction('requestUpgrade', $member)
            ->assertNotified('Upgrade request recorded')
            ->assertCanNotSeeTableRecords([$member]);

        $this->assertSame('2026-10-07', $member->refresh()->category_upgrade_requested_at->toDateString());

        Livewire::withQueryParams(['tab' => 'upgrades'])
            ->test(Monitoring::class)
            ->assertCanSeeTableRecords([$member]);
    }

    public function test_a_member_who_asked_not_to_be_texted_is_skipped_and_cannot_be_texted_one_by_one(): void
    {
        $this->fakeGatewayAcceptingMessages();
        $optedOut = Member::factory()->waiting()->create(['contact_number' => '09181113333', 'sms_opt_out' => true]);
        $wantsSms = Member::factory()->waiting()->create(['contact_number' => '09181114444']);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(Monitoring::class)
            ->assertTableActionDisabled('sendSms', $optedOut)
            ->callTableBulkAction('sendSmsToSelected', [$optedOut, $wantsSms])
            ->assertNotified('1 SMS queued for sending');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->data()['to'] === '09181114444');
    }

    public function test_turning_on_the_opt_out_records_the_date_of_the_request(): void
    {
        $this->travelTo('2026-10-18');
        $member = Member::factory()->create();

        $member->update(['sms_opt_out' => true]);
        $this->assertSame('2026-10-18', $member->fresh()->sms_opt_out_at->toDateString());
        $this->assertSame('1', (string) $member->histories()->where('field', 'sms_opt_out')->value('new_value'));

        $member->update(['sms_opt_out' => false]);
        $this->assertNull($member->fresh()->sms_opt_out_at);
    }

    public function test_selected_members_each_get_the_effectivity_reminder_and_those_without_a_number_are_skipped(): void
    {
        $this->fakeGatewayAcceptingMessages();
        $withNumber = Member::factory()->waiting()->create(['first_name' => 'Juan', 'contact_number' => '09181112222', 'approval_date' => '2026-09-05']);
        $withoutNumber = Member::factory()->waiting()->create(['contact_number' => null]);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(Monitoring::class)
            ->callTableBulkAction('sendSmsToSelected', [$withNumber, $withoutNumber])
            ->assertNotified('1 SMS queued for sending');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->data() === [
            'to' => '09181112222',
            'message' => 'Good day, Juan! Your COLISAP membership takes effect on Mar 4, 2027. Please keep at least P500 in your savings to stay covered. - Barbaza MPC',
        ]);
    }

    public function test_send_to_all_texts_everyone_listed_in_the_replenishment_view(): void
    {
        $this->travelTo('2026-10-05 09:00:00');
        $this->fakeGatewayAcceptingMessages();
        $open = ReplenishmentNotice::factory()->create([
            'member_id' => Member::factory()->create(['first_name' => 'Ana', 'contact_number' => '09171234567', 'savings_balance' => 300]),
            'deadline' => '2026-10-15',
        ]);
        ReplenishmentNotice::factory()->create([
            'member_id' => Member::factory()->create(['contact_number' => '09170000000']),
            'status' => 'replenished',
        ]);
        $this->actingAsRole(Permissions::CRS);

        Livewire::withQueryParams(['tab' => 'replenishment'])
            ->test(Monitoring::class)
            ->assertCanSeeTableRecords([$open])
            ->callTableAction('sendSmsToAll')
            ->assertNotified('1 SMS queued for sending');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->data() === [
            'to' => '09171234567',
            'message' => 'Good day, Ana! Your savings of P300 is below the P500 COLISAP maintaining balance. Please deposit at least P200 on or before Oct 15, 2026 to keep your coverage. - Barbaza MPC',
        ]);
    }

    public function test_a_message_the_gateway_refuses_is_reported_and_not_logged_as_sent(): void
    {
        Http::fake(['sms.test/*' => Http::response([], 422)]);
        $member = Member::factory()->dormant()->create(['contact_number' => '09171234567']);
        $this->actingAsRole(Permissions::CRS);

        Livewire::withQueryParams(['tab' => 'dormancy'])
            ->test(Monitoring::class)
            ->callTableBulkAction('sendSmsToSelected', [$member])
            ->assertNotified('No SMS was sent');

        $this->assertSame(0, $member->auditLogs()->where('action', 'sms_sent')->count());
    }

    public function test_nothing_is_sent_until_the_gateway_api_key_is_configured(): void
    {
        Http::fake();
        config(['services.etxtmo.key' => null]);
        $member = Member::factory()->waiting()->create(['contact_number' => '09171234567']);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(Monitoring::class)
            ->callTableBulkAction('sendSmsToSelected', [$member])
            ->assertNotified('SMS is not set up yet');

        Http::assertNothingSent();
    }

    public function test_send_sms_is_hidden_without_the_permission_and_disabled_without_a_mobile_number(): void
    {
        $withNumber = Member::factory()->waiting()->create(['contact_number' => '09171234567']);
        $withoutNumber = Member::factory()->waiting()->create(['contact_number' => 'none']);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(Monitoring::class)
            ->assertTableActionVisible('sendSms', $withNumber)
            ->assertTableActionEnabled('sendSms', $withNumber)
            ->assertTableActionDisabled('sendSms', $withoutNumber);

        Role::findByName(Permissions::CRS, 'web')->revokePermissionTo('monitoring.send_sms');

        Livewire::test(Monitoring::class)
            ->assertTableActionHidden('sendSms', $withNumber)
            ->assertTableActionHidden('sendSmsToAll')
            ->assertTableBulkActionHidden('sendSmsToSelected');
    }
}
