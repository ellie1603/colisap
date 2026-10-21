<?php

namespace Tests\Feature\Models;

use App\Filament\Resources\ApplicationResource;
use App\Filament\Resources\ApplicationResource\Pages\EditApplication;
use App\Filament\Resources\ApplicationResource\Pages\ListApplications;
use App\Filament\Resources\MemberResource\Pages\ViewMember;
use App\Models\Application;
use App\Models\Branch;
use App\Models\Member;
use App\Models\User;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    private const ACCOUNT = '00101959720';

    /**
     * What a qualified new applicant enters on the New transaction form.
     *
     * @return array<string, mixed>
     */
    private function applicantData(array $overrides = []): array
    {
        return [
            'type' => 'new',
            'account_no' => self::ACCOUNT,
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan',
            'branch_id' => Branch::where('name', 'Culasi')->value('id'),
            'birthdate' => '1990-05-20',
            'savings_balance' => 750,
            'application_date' => '2026-10-12',
            'category' => '40000',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, array{0: ?string, 1: list<array{0: string, 1: string}>, 2: string, 3: ?string}>
     */
    public static function filingHistories(): array
    {
        return [
            'someone who is not a member yet' => [null, [], 'new', null],
            'an application for this number is already pending' => [null, [['new', 'pending']], 'new', 'already a pending application'],
            'their application was rejected: reopen it' => [null, [['new', 'rejected']], 'new', 'Reopen that application'],
            'the number already belongs to a member' => ['active', [['new', 'approved']], 'new', 'already belongs to a member'],
            're-application for a number nobody has' => [null, [], 'reapplication', 'No member has this Acct. Number'],
            're-application while still participating' => ['active', [['new', 'approved']], 'reapplication', 'only for a member whose participation was terminated or withdrawn'],
            're-application after termination' => ['terminated', [['new', 'approved']], 'reapplication', null],
            're-application after withdrawal' => ['withdrawn', [['new', 'approved']], 'reapplication', null],
            'second re-application while one is pending' => ['terminated', [['new', 'approved'], ['reapplication', 'pending']], 'reapplication', 'already a pending application'],
            'rejected re-application: reopen it' => ['terminated', [['new', 'approved'], ['reapplication', 'rejected']], 'reapplication', 'Reopen that application'],
            'terminated again after an approved re-application' => ['terminated', [['new', 'approved'], ['reapplication', 'approved']], 'reapplication', null],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $history
     */
    #[DataProvider('filingHistories')]
    public function test_a_double_application_cannot_be_filed_for_the_same_account_number(?string $memberStatus, array $history, string $type, ?string $expectedProblem): void
    {
        $member = $memberStatus === null ? null : Member::factory()->create(['account_no' => self::ACCOUNT, 'status' => $memberStatus]);

        foreach ($history as [$existingType, $existingStatus]) {
            $member === null
                ? Application::factory()->applicant()->create(['account_no' => self::ACCOUNT, 'type' => $existingType, 'status' => $existingStatus])
                : Application::factory()->create(['member_id' => $member->id, 'type' => $existingType, 'status' => $existingStatus]);
        }

        $problem = Application::filingProblem($type, self::ACCOUNT);

        $expectedProblem === null
            ? $this->assertNull($problem)
            : $this->assertStringContainsString($expectedProblem, (string) $problem);
    }

    public function test_filing_an_application_records_the_applicant_without_adding_a_member(): void
    {
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListApplications::class)
            ->callAction('create', data: $this->applicantData())
            ->assertHasNoActionErrors()
            ->assertNotified('Transaction saved')
            ->assertNoRedirect();

        $application = Application::sole();

        $this->assertSame(0, Member::count());
        $this->assertSame(
            ['pending', null, self::ACCOUNT, 'DELA CRUZ, JUAN', 'Culasi', null],
            [$application->status, $application->member_id, $application->applicantAccountNo(), $application->applicantName(), $application->applicantBranchName(), $application->contact_number],
        );
    }

    public function test_approving_an_application_adds_the_applicant_to_members_with_the_declared_balance(): void
    {
        $this->travelTo('2026-10-12 09:00:00');
        $application = Application::factory()->applicant()->create([
            'account_no' => self::ACCOUNT, 'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'savings_balance' => 750,
            'contact_number' => '09171234567', 'category' => '40000',
        ]);
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(EditApplication::class, ['record' => $application->getRouteKey()])
            ->callAction('approve', ['approved_at' => '2026-10-12'])
            ->assertNotified('Transaction approved');

        $member = Member::sole();
        $application->refresh();

        $this->assertSame(['approved', $member->id], [$application->status, $application->member_id]);
        $this->assertSame(
            [self::ACCOUNT, 'DELA CRUZ, JUAN', 'waiting', '40000', '750.00', '2026-10-12', '09171234567'],
            [$member->account_no, $member->account_name, $member->status, $member->category, $member->savings_balance, $member->approval_date->toDateString(), $member->contact_number],
        );
        $this->assertSame(1, Application::where('member_id', $member->id)->count(), 'Approval must not create a second application.');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function unqualifiedApplicants(): array
    {
        return [
            'savings below the maintaining balance' => [['savings_balance' => 499], 'savings_balance'],
            'savings below the higher category balance' => [['category' => '60000', 'savings_balance' => 1500], 'savings_balance'],
            'over 59 years and 6 months old' => [['birthdate' => '1960-01-01'], 'birthdate'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('unqualifiedApplicants')]
    public function test_an_applicant_who_does_not_meet_the_policy_cannot_apply(array $overrides, string $field): void
    {
        $this->travelTo('2026-10-12 09:00:00');
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListApplications::class)
            ->callAction('create', data: $this->applicantData($overrides))
            ->assertHasActionErrors([$field]);

        $this->assertSame([0, 0], [Application::count(), Member::count()]);
    }

    public function test_an_application_is_filed_without_a_qualification_checklist(): void
    {
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListApplications::class)
            ->mountAction('create')
            ->assertDontSee('Qualification checklist')
            ->callMountedAction()
            ->assertHasActionErrors();

        Livewire::test(ListApplications::class)
            ->callAction('create', data: $this->applicantData())
            ->assertHasNoActionErrors()
            ->assertNotified('Transaction saved');

        $this->assertSame('pending', Application::sole()->status);
    }

    public function test_the_application_form_refuses_a_second_application_for_the_same_account_number(): void
    {
        Application::factory()->applicant()->create(['account_no' => self::ACCOUNT]);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListApplications::class)
            ->callAction('create', data: $this->applicantData())
            ->assertHasActionErrors(['account_no']);

        $this->assertSame(1, Application::count());
    }

    public function test_a_re_application_is_filed_by_account_number_and_linked_to_the_returning_member(): void
    {
        $member = Member::factory()->terminated()->create(['account_no' => self::ACCOUNT]);
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(ListApplications::class)
            ->callAction('create', data: [
                'type' => 'reapplication',
                'account_no' => self::ACCOUNT,
                'application_date' => now()->toDateString(),
                'category' => '40000',
                'or_number' => 'OR-20261019-07',
            ])
            ->assertHasNoActionErrors();

        $application = Application::sole();
        $this->assertSame(['OR-20261019-07', true], [$application->or_number, $application->fee_paid]);

        $this->assertSame(
            [$member->id, 'reapplication', 'pending', 'terminated', '30.00'],
            [$application->member_id, $application->type, $application->status, $application->previous_status, $application->fee_amount],
        );
    }

    public function test_branch_staff_file_applications_but_only_the_administrator_decides_them(): void
    {
        $application = Application::factory()->applicant()->create();
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListApplications::class)->assertActionVisible('create');
        $this->get(ApplicationResource::getUrl('edit', ['record' => $application]))->assertForbidden();
    }

    public function test_staff_limited_to_a_branch_see_only_that_branchs_applications(): void
    {
        $culasi = Branch::where('name', 'Culasi')->value('id');
        $own = Application::factory()->applicant()->create(['branch_id' => $culasi]);
        $other = Application::factory()->applicant()->create(['branch_id' => Branch::where('name', 'Kalibo')->value('id')]);
        $this->actingAs(User::factory()->create(['is_active' => true, 'branch_id' => $culasi])->assignRole(Permissions::CRS));

        Livewire::test(ListApplications::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_a_rejected_application_is_reopened_and_approved_instead_of_filing_a_second_one(): void
    {
        $application = Application::factory()->create(['status' => 'rejected', 'rejection_reason' => 'Incomplete documents']);
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(EditApplication::class, ['record' => $application->getRouteKey()])
            ->assertActionHidden('approve')
            ->callAction('reopen')
            ->assertNotified('Transaction reopened')
            ->assertActionHidden('reopen')
            ->callAction('approve', ['approved_at' => now()->toDateString()]);

        $application->refresh();

        $this->assertSame(['approved', null], [$application->status, $application->rejection_reason]);
        $this->assertSame(1, Application::where('member_id', $application->member_id)->count());
    }

    public function test_a_pending_application_can_be_saved_with_new_remarks(): void
    {
        $application = Application::factory()->create(['status' => 'pending']);
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(EditApplication::class, ['record' => $application->getRouteKey()])
            ->fillForm(['remarks' => 'Documents received at the branch'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Documents received at the branch', $application->refresh()->remarks);
    }

    public function test_start_re_application_is_only_offered_when_it_would_not_be_a_double(): void
    {
        $terminated = Member::factory()->terminated()->create();
        $withRejected = Member::factory()->terminated()->create();
        Application::factory()->reapplication()->create(['member_id' => $withRejected->id, 'status' => 'rejected']);
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(ViewMember::class, ['record' => $terminated->getRouteKey()])->assertActionVisible('reapply');
        Livewire::test(ViewMember::class, ['record' => $withRejected->getRouteKey()])->assertActionHidden('reapply');
    }
}
