<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Monitoring;
use App\Filament\Resources\MemberResource;
use App\Filament\Resources\MemberResource\Pages\ListMembers;
use App\Filament\Widgets\StatusByBranchChart;
use App\Models\Branch;
use App\Models\Member;
use App\Models\ReplenishmentNotice;
use App\Models\User;
use App\Services\Access\Permissions;
use App\Services\Colisap\MonitoringService;
use App\Services\Reports\ReportService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BranchRestrictionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function branchId(string $name): int
    {
        return (int) Branch::where('name', $name)->value('id');
    }

    /**
     * Sign in as active staff whose home branch is the given one (null = no home branch).
     */
    private function actingAsStaff(string $role, ?string $homeBranch): User
    {
        $user = User::factory()
            ->create(['is_active' => true, 'branch_id' => $homeBranch ? $this->branchId($homeBranch) : null])
            ->assignRole($role);

        $this->actingAs($user);

        return $user;
    }

    public function test_crs_officer_with_a_home_branch_sees_only_that_branch_on_the_dashboard(): void
    {
        Member::factory()->count(2)->create(['branch_id' => $this->branchId('Barbaza')]);
        Member::factory()->waiting()->create(['branch_id' => $this->branchId('Kalibo')]);
        ReplenishmentNotice::factory()->create(['member_id' => Member::factory()->create(['branch_id' => $this->branchId('Kalibo')])]);
        $this->actingAsStaff(Permissions::CRS, 'Barbaza');

        $monitoring = app(MonitoringService::class);

        $this->assertSame(
            ['waiting' => 0, 'active' => 2, 'dormant' => 0, 'deceased' => 0, 'terminated' => 0, 'withdrawn' => 0],
            $monitoring->statusCounts(),
        );
        $this->assertSame(['Barbaza'], array_keys($monitoring->statusByBranch()));
        $this->assertSame(0, $monitoring->replenishmentSummary()['Open notices']);
        $this->assertFalse(StatusByBranchChart::canView());
        $this->get('/admin')->assertOk()->assertSee('for the Barbaza branch');
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function staffWhoSeeEveryBranch(): array
    {
        return [
            'administrator with a home branch' => [Permissions::ADMIN, 'Barbaza'],
            'crs officer without a home branch' => [Permissions::CRS, null],
        ];
    }

    #[DataProvider('staffWhoSeeEveryBranch')]
    public function test_administrators_and_staff_without_a_home_branch_see_every_branch(string $role, ?string $homeBranch): void
    {
        Member::factory()->create(['branch_id' => $this->branchId('Barbaza')]);
        Member::factory()->create(['branch_id' => $this->branchId('Kalibo')]);
        $this->actingAsStaff($role, $homeBranch);

        $this->assertSame(2, app(MonitoringService::class)->statusCounts()['active']);
        $this->assertCount(15, app(MonitoringService::class)->statusByBranch());
        $this->assertTrue(StatusByBranchChart::canView());
        $this->assertCount(15, Branch::options());
    }

    public function test_member_list_and_monitoring_hide_members_of_other_branches(): void
    {
        $own = Member::factory()->waiting()->create(['branch_id' => $this->branchId('Barbaza')]);
        $other = Member::factory()->waiting()->create(['branch_id' => $this->branchId('Kalibo')]);
        $this->actingAsStaff(Permissions::CRS, 'Barbaza');

        Livewire::test(ListMembers::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(Monitoring::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other]);

        $this->assertSame(['Barbaza'], array_values(Branch::options()));
    }

    public function test_opening_a_member_of_another_branch_returns_404(): void
    {
        $own = Member::factory()->create(['branch_id' => $this->branchId('Barbaza')]);
        $other = Member::factory()->create(['branch_id' => $this->branchId('Kalibo')]);
        $this->actingAsStaff(Permissions::CRS, 'Barbaza');

        $this->get(MemberResource::getUrl('view', ['record' => $own]))->assertOk();
        $this->get(MemberResource::getUrl('view', ['record' => $other]))->assertNotFound();
        $this->get(MemberResource::getUrl('edit', ['record' => $other]))->assertNotFound();
    }

    public function test_reports_cover_only_the_home_branch_even_when_another_branch_is_requested(): void
    {
        Member::factory()->create(['account_no' => '00101000001', 'branch_id' => $this->branchId('Barbaza')]);
        Member::factory()->create(['account_no' => '00101000002', 'branch_id' => $this->branchId('Kalibo')]);
        $this->actingAsStaff(Permissions::CRS, 'Barbaza');
        $reports = app(ReportService::class);

        $masterlist = $reports->build('masterlist', ['branch_ids' => [$this->branchId('Kalibo')]]);

        $this->assertSame(['00101000001'], array_column(iterator_to_array($masterlist['rows'], false), 0));
        $this->assertSame(['Branch: Barbaza'], $masterlist['filters']);
        $this->assertSame(['Barbaza', 'Total'], array_column($reports->build('branch_summary')['rows'], 0));
    }
}
