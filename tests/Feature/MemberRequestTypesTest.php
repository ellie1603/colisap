<?php

namespace Tests\Feature;

use App\Filament\Resources\ApplicationResource;
use App\Filament\Resources\ApplicationResource\Pages\EditApplication;
use App\Filament\Resources\ApplicationResource\Pages\ListApplications;
use App\Models\Application;
use App\Models\Branch;
use App\Models\Member;
use App\Models\User;
use App\Services\Access\Permissions;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

/**
 * The CRS Officer's member transactions: new member, upgrade and re-application (with OR number); "active member" ones filed before are still decided.
 */
class MemberRequestTypesTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    public function test_the_transaction_form_offers_new_upgrade_and_re_application_without_an_empty_choice(): void
    {
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListApplications::class)
            ->mountAction('create')
            ->assertSee('New transaction')
            ->assertSee('New member')
            ->assertSee('Upgrade to 60K')
            ->assertSee('Re-application')
            ->assertSee('Transaction date')
            ->assertFormFieldExists('type', 'mountedActionForm', fn (Select $field): bool => ! $field->canSelectPlaceholder()
                && array_keys($field->getOptions()) === ['new', 'upgrade', 'reapplication']);
    }

    public function test_an_active_member_transaction_can_no_longer_be_filed(): void
    {
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListApplications::class)
            ->callAction('create', data: [
                'type' => 'active', 'account_no' => '00101777101', 'last_name' => 'Cruz', 'first_name' => 'Ana',
                'branch_id' => Branch::where('name', 'Barbaza')->value('id'), 'birthdate' => '1980-01-01', 'savings_balance' => 800,
                'category' => '40000', 'member_since' => '2020-06-15',
            ])
            ->assertHasActionErrors(['type']);

        $this->assertSame(0, Application::count());
    }

    public function test_an_active_member_transaction_filed_before_is_still_approved_with_its_original_approval_date(): void
    {
        $this->travelTo('2026-10-19 09:00:00');
        $request = Application::factory()->applicant()->create([
            'type' => 'active', 'account_no' => '00101777100', 'last_name' => 'Santos', 'first_name' => 'Maria',
            'savings_balance' => 2500, 'application_date' => '2026-10-19', 'member_since' => '2020-06-15',
        ]);

        $this->actingAsRole(Permissions::ADMIN);
        Livewire::test(ListApplications::class)->assertSee('Active member');
        Livewire::test(EditApplication::class, ['record' => $request->getRouteKey()])
            ->callAction('approve', ['approved_at' => '2026-10-19'])
            ->assertNotified('Transaction approved');

        $member = Member::sole();
        $this->assertSame(['00101777100', '2020-06-15', 'active'], [$member->account_no, $member->approval_date->toDateString(), $member->status]);
    }

    public function test_an_upgrade_request_starts_the_upgrade_waiting_period_once_approved(): void
    {
        $this->travelTo('2026-10-19 09:00:00');
        $member = Member::factory()->create(['account_no' => '00101777102', 'status' => 'active', 'category' => '40000', 'savings_balance' => 2500]);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListApplications::class)
            ->callAction('create', data: ['type' => 'upgrade', 'account_no' => '00101777102', 'application_date' => '2026-10-19'])
            ->assertHasNoActionErrors();

        $request = Application::sole();
        $this->assertSame([$member->id, '60000', 'pending'], [$request->member_id, $request->category, $request->status]);
        $this->assertNull($member->fresh()->category_upgrade_requested_at, 'Nothing changes before approval.');

        $this->actingAsRole(Permissions::ADMIN);
        Livewire::test(EditApplication::class, ['record' => $request->getRouteKey()])
            ->callAction('approve', ['approved_at' => '2026-10-19'])
            ->assertNotified('Transaction approved');

        $member->refresh();
        $this->assertSame(['2026-10-19', '40000', 'active'], [$member->category_upgrade_requested_at->toDateString(), $member->category, $member->status]);
        $this->assertSame('pending', $member->upgradeStatus());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function membersWhoCannotUpgrade(): array
    {
        return [
            'already in the higher category' => [['status' => 'active', 'category' => '60000'], 'already in the 60K'],
            'not active' => [['status' => 'dormant', 'category' => '40000'], 'Only an active member can upgrade'],
            'already asked' => [['status' => 'active', 'category' => '40000', 'category_upgrade_requested_at' => '2026-09-01'], 'already asked to upgrade'],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    #[DataProvider('membersWhoCannotUpgrade')]
    public function test_an_upgrade_is_refused_for_a_member_who_cannot_upgrade(array $state, string $reason): void
    {
        Member::factory()->create(['account_no' => '00101777103', ...$state]);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListApplications::class)
            ->callAction('create', data: ['type' => 'upgrade', 'account_no' => '00101777103', 'application_date' => now()->toDateString()])
            ->assertHasActionErrors(['account_no']);

        $this->assertStringContainsString($reason, Application::upgradeProblem(Member::where('account_no', '00101777103')->first()));
        $this->assertSame(0, Application::count());
    }

    public function test_a_re_application_needs_the_or_number_of_the_fee(): void
    {
        Member::factory()->terminated()->create(['account_no' => '00101777104']);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListApplications::class)
            ->callAction('create', data: ['type' => 'reapplication', 'account_no' => '00101777104', 'application_date' => now()->toDateString(), 'category' => '40000'])
            ->assertHasActionErrors(['or_number' => 'required']);

        $this->assertSame(0, Application::count());
    }

    private function actingAsBranchManagerOf(string $branchName): User
    {
        $manager = User::factory()->create(['is_active' => true, 'branch_id' => Branch::where('name', $branchName)->value('id')])
            ->assignRole(Permissions::BRANCH_MANAGER);
        $this->actingAs($manager);

        return $manager;
    }

    public function test_the_branch_manager_approves_a_re_application_of_their_branch_sent_by_the_crs_officer(): void
    {
        $member = Member::factory()->terminated()->create(['account_no' => '00101777105', 'branch_id' => Branch::where('name', 'Barbaza')->value('id')]);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListApplications::class)
            ->callAction('create', data: ['type' => 'reapplication', 'account_no' => '00101777105', 'application_date' => now()->toDateString(), 'category' => '40000', 'or_number' => 'OR-1001'])
            ->assertHasNoActionErrors()
            ->assertNotified('Transaction saved');

        $reapplication = Application::sole();
        $this->actingAsBranchManagerOf('Barbaza');

        Livewire::test(ListApplications::class)
            ->assertCanSeeTableRecords([$reapplication])
            ->assertActionHidden('create')
            ->assertActionHidden('importSavings');
        Livewire::test(EditApplication::class, ['record' => $reapplication->getRouteKey()])
            ->assertActionVisible('approve')
            ->callAction('approve', ['approved_at' => now()->toDateString()])
            ->assertNotified('Transaction approved');

        $this->assertSame('approved', $reapplication->fresh()->status);
        $this->assertSame('waiting', $member->fresh()->status, 'Reinstated, starting the waiting period again.');
    }

    public function test_the_branch_manager_decides_neither_another_branchs_re_applications_nor_new_members(): void
    {
        $kaliboReapplication = Application::factory()->reapplication()->create([
            'member_id' => Member::factory()->terminated()->create(['branch_id' => Branch::where('name', 'Kalibo')->value('id')]),
        ]);
        $barbazaNewMember = Application::factory()->applicant()->create(['branch_id' => Branch::where('name', 'Barbaza')->value('id')]);
        $manager = $this->actingAsBranchManagerOf('Barbaza');

        $this->assertFalse($kaliboReapplication->canBeDecidedBy($manager));
        $this->assertFalse($barbazaNewMember->canBeDecidedBy($manager), 'New members stay with the Administrator.');
        $this->get(ApplicationResource::getUrl('edit', ['record' => $barbazaNewMember]))->assertForbidden();
    }
}
