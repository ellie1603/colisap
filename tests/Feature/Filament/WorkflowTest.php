<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Monitoring;
use App\Filament\Resources\ApplicationResource\Pages\EditApplication;
use App\Filament\Resources\MemberResource\Pages\ViewMember;
use App\Models\Application;
use App\Models\Member;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    public function test_approving_an_application_starts_the_waiting_period(): void
    {
        $this->travelTo('2026-06-30 08:00:00');
        $application = Application::factory()->create(['category' => '60000']);
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(EditApplication::class, ['record' => $application->getRouteKey()])
            ->callAction('approve', ['approved_at' => '2026-06-30']);

        $member = $application->member->fresh();
        $this->assertSame(['approved', 'waiting', '60000', '2026-12-27'], [
            $application->fresh()->status, $member->status, $member->category, $member->effectivityDate()->toDateString(),
        ]);
    }

    public function test_application_with_unchecked_requirements_cannot_be_approved(): void
    {
        $application = Application::factory()->create(['good_health_declared' => false]);
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(EditApplication::class, ['record' => $application->getRouteKey()])
            ->callAction('approve', ['approved_at' => now()->toDateString()])
            ->assertNotified('Requirements incomplete');

        $this->assertSame('pending', $application->fresh()->status);
    }

    public function test_voluntary_withdrawal_and_reapplication_preserve_history(): void
    {
        Storage::fake('local');
        $member = Member::factory()->create();
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
            ->callAction('withdraw', [
                'withdrawn_at' => now()->toDateString(),
                'withdrawal_reason' => 'Moving abroad',
                'withdrawal_document' => UploadedFile::fake()->create('letter.pdf', 20, 'application/pdf'),
            ]);

        $member->refresh();
        $this->assertSame('withdrawn', $member->status);
        Storage::disk('local')->assertExists($member->withdrawal_document);

        Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
            ->callAction('reapply', ['application_date' => now()->toDateString(), 'category' => '40000', 'fee_paid' => true]);

        $reapplication = $member->applications()->where('type', 'reapplication')->sole();
        $this->assertSame(['pending', '30.00', 'withdrawn'], [$reapplication->status, $reapplication->fee_amount, $reapplication->previous_status]);

        $reapplication->approve(now());
        $this->assertSame('waiting', $member->fresh()->status);
        $this->assertSame(['active', 'withdrawn', 'waiting'], $member->histories()->where('field', 'status')->reorder('id')->pluck('new_value')->all());
    }

    public function test_eligible_upgrades_are_applied_from_monitoring(): void
    {
        $eligible = Member::factory()->create(['category_upgrade_requested_at' => now()->subDays(90), 'savings_balance' => 3000]);
        $short = Member::factory()->create(['category_upgrade_requested_at' => now()->subDays(95), 'savings_balance' => 1000]);
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::withQueryParams(['tab' => 'upgrades'])
            ->test(Monitoring::class)
            ->callTableBulkAction('applyAll', [$eligible, $short])
            ->assertNotified('1 member(s) moved to 60K');

        $this->assertSame(['60000', '40000'], [$eligible->fresh()->category, $short->fresh()->category]);
    }

    public function test_monitoring_tabs_switch_in_place_and_rebuild_the_table_for_the_new_view(): void
    {
        Member::factory()->waiting()->create(['approval_date' => now()->subDays(100)]);
        $eligible = Member::factory()->create(['category_upgrade_requested_at' => now()->subDays(90), 'savings_balance' => 3000]);
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(Monitoring::class)
            ->assertSet('tab', 'effectivity')
            ->assertSee('Effective on')
            ->call('switchTab', 'upgrades')
            ->assertSet('tab', 'upgrades')
            ->assertSee('Eligible on')
            ->assertDontSee('Effective on')
            ->callTableBulkAction('applyAll', [$eligible])
            ->assertNotified('1 member(s) moved to 60K')
            ->call('switchTab', 'not-a-view')
            ->assertSet('tab', 'upgrades');
    }
}
