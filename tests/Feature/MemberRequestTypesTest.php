<?php

namespace Tests\Feature;

use App\Filament\Resources\ApplicationResource\Pages\EditApplication;
use App\Filament\Resources\ApplicationResource\Pages\ListApplications;
use App\Models\Application;
use App\Models\Branch;
use App\Models\Member;
use App\Services\Access\Permissions;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

/**
 * The CRS Officer's member transactions: new member, active member, upgrade and re-application (with OR number).
 */
class MemberRequestTypesTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    public function test_the_request_form_offers_the_four_types_without_an_empty_choice(): void
    {
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListApplications::class)
            ->mountAction('create')
            ->assertSee('New transaction')
            ->assertSee('New member')
            ->assertSee('Active member')
            ->assertSee('Upgrade to 60K')
            ->assertSee('Re-application')
            ->assertFormFieldExists('type', 'mountedActionForm', fn (Select $field): bool => ! $field->canSelectPlaceholder()
                && array_keys($field->getOptions()) === ['new', 'active', 'upgrade', 'reapplication']);
    }

    public function test_an_already_enrolled_member_is_added_as_active_with_their_original_approval_date(): void
    {
        $this->travelTo('2026-10-19 09:00:00');
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListApplications::class)
            ->callAction('create', data: [
                'type' => 'active', 'account_no' => '00101777100', 'last_name' => 'Santos', 'first_name' => 'Maria',
                'branch_id' => Branch::where('name', 'Barbaza')->value('id'), 'birthdate' => '1962-03-01', // over the entry age today: allowed, she joined in 2020
                'savings_balance' => 2500, 'application_date' => '2026-10-19', 'member_since' => '2020-06-15', 'category' => '40000',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Transaction saved');

        $request = Application::sole();
        $this->assertSame(['active', 'pending', '2020-06-15'], [$request->type, $request->status, $request->member_since->toDateString()]);
        $this->assertSame(0, Member::count(), 'Nobody is added before the Administrator approves.');

        $this->actingAsRole(Permissions::ADMIN);
        Livewire::test(EditApplication::class, ['record' => $request->getRouteKey()])
            ->callAction('approve', ['approved_at' => '2026-10-19'])
            ->assertNotified('Transaction approved');

        $member = Member::sole();
        $this->assertSame(['00101777100', '2020-06-15', 'active'], [$member->account_no, $member->approval_date->toDateString(), $member->status]);
    }

    public function test_an_active_member_request_needs_the_original_approval_date(): void
    {
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListApplications::class)
            ->callAction('create', data: [
                'type' => 'active', 'account_no' => '00101777101', 'last_name' => 'Cruz', 'first_name' => 'Ana',
                'branch_id' => Branch::where('name', 'Barbaza')->value('id'), 'birthdate' => '1980-01-01', 'savings_balance' => 800, 'category' => '40000',
            ])
            ->assertHasActionErrors(['member_since']);
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
}
