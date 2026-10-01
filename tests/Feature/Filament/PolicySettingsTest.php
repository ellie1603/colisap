<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\PolicySettings;
use App\Models\AuditLog;
use App\Models\Member;
use App\Services\Access\Permissions;
use App\Services\Policy\PolicySettings as PolicySettingsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class PolicySettingsTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    public function test_defaults_match_the_colisap_policy(): void
    {
        $policy = app(PolicySettingsService::class);

        $this->assertSame(
            [40000.0, 60000.0, 500.0, 2000.0, 180, 90, 15, 3, 30.0, 5.0, 100, 1, 3, 100.0, 50.0],
            [
                $policy->decimal('benefit_40k'), $policy->decimal('benefit_60k'), $policy->decimal('min_balance_40k'), $policy->decimal('min_balance_60k'),
                $policy->int('effectivity_days'), $policy->int('upgrade_wait_days'), $policy->int('replenishment_days'), $policy->int('dormancy_months'),
                $policy->decimal('reapplication_fee'), $policy->decimal('claim_contribution'), $policy->int('min_program_participants'),
                $policy->int('min_beneficiaries'), $policy->int('max_beneficiaries'), $policy->decimal('coop_share_diamond'), $policy->decimal('coop_share_gold'),
            ],
        );
    }

    public function test_super_admin_changes_are_saved_and_audited(): void
    {
        $user = $this->actingAsRole(Permissions::SUPER_ADMIN);

        Livewire::test(PolicySettings::class)
            ->set('data.min_balance_40k', 750)
            ->call('save')
            ->assertNotified('Policy settings saved');

        $this->assertSame(750.0, app(PolicySettingsService::class)->decimal('min_balance_40k'));
        $this->assertTrue(AuditLog::where('module', 'Policy Setting')->where('user_id', $user->id)->exists());
    }

    public function test_changed_policy_drives_member_rules(): void
    {
        $member = Member::factory()->create(['savings_balance' => 600]);
        $this->assertTrue($member->meetsMinimumBalance());

        app(PolicySettingsService::class)->update(['min_balance_40k' => 750]);

        $this->assertFalse($member->fresh()->meetsMinimumBalance());
    }

    public function test_admin_cannot_change_policy(): void
    {
        $this->actingAsRole(Permissions::ADMIN);

        $this->get(PolicySettings::getUrl())->assertForbidden();
    }
}
