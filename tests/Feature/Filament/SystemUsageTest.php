<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Reports;
use App\Filament\Pages\SystemUsage;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\Member;
use App\Models\User;
use App\Services\Access\Permissions;
use App\Services\Reports\StaffUsageReport;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class SystemUsageTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    private function staff(string $name, bool $isActive = true): User
    {
        return User::factory()->create(['name' => $name, 'is_active' => $isActive])->assignRole(Permissions::CRS);
    }

    public function test_the_report_counts_each_staff_accounts_sign_ins_and_work_for_the_period(): void
    {
        $this->travelTo('2026-10-07 10:00:00');
        $busy = User::factory()->create(['name' => 'Busy Officer', 'is_active' => true, 'branch_id' => Branch::where('name', 'Culasi')->value('id')])
            ->assignRole(Permissions::CRS);
        $visitor = $this->staff('Visiting Officer');
        $absent = $this->staff('Absent Officer');
        $former = $this->staff('Former Officer', isActive: false);

        $this->actingAs($busy);
        $busy->recordActivity('login', 'Sign-in');
        Member::factory()->count(2)->create();
        ImportBatch::create(['file_name' => 'masterlist.xlsx', 'as_of_date' => now(), 'status' => 'completed', 'created_count' => 40, 'updated_count' => 10, 'uploaded_by' => $busy->id]);
        $busy->recordActivity('report_exported', 'Reports');

        $this->travelTo('2026-10-06 09:00:00');
        $busy->recordActivity('login', 'Sign-in');
        $visitor->recordActivity('login', 'Sign-in');

        $this->travelTo('2026-09-20 09:00:00');
        $absent->recordActivity('login', 'Sign-in');

        $this->travelTo('2026-10-07 17:00:00');
        $report = app(StaffUsageReport::class)->build('this_week');

        $this->assertSame('Oct 5 – Oct 7, 2026', $report['range_label']);
        $this->assertSame(
            ['accounts' => 3, 'using' => 1, 'signed_in_only' => 1, 'no_activity' => 1, 'sign_ins' => 3, 'members_added' => 2, 'files_imported' => 1, 'records_imported' => 50, 'reports_exported' => 1],
            array_intersect_key($report['summary'], array_flip(['accounts', 'using', 'signed_in_only', 'no_activity', 'sign_ins', 'members_added', 'files_imported', 'records_imported', 'reports_exported'])),
        );
        $this->assertSame('1 of 3 staff accounts used the system (33%).', StaffUsageReport::headline($report['summary']));
        $busyRow = $report['staff'][$busy->id];
        $this->assertSame(
            ['using', 2, 2, 'CRS Officer', 'Culasi'],
            [$busyRow['status'], $busyRow['sign_ins'], $busyRow['days_active'], $busyRow['role'], $busyRow['branch']],
        );
        $this->assertSame(
            ['signed_in', 'none', 'deactivated'],
            [$report['staff'][$visitor->id]['status'], $report['staff'][$absent->id]['status'], $report['staff'][$former->id]['status']],
        );
        $this->assertSame('2026-09-20', $report['staff'][$absent->id]['last_active']->toDateString());
        $this->assertSame('Signed in 2 times, added 2 members, uploaded 1 Excel file (50 member records), downloaded 1 report.', $busyRow['did']);
        $this->assertSame(['2026-10-06', '2026-10-07'], collect($busyRow['active_days'])->sort()->values()->all());
        $this->assertSame(['Signed in once.', 'Nothing recorded in this period.'], [$report['staff'][$visitor->id]['did'], $report['staff'][$absent->id]['did']]);
        $this->assertSame(['2026-10-05', '2026-10-06', '2026-10-07'], array_map(fn ($day) => $day->toDateString(), $report['days']));
        $this->assertSame(
            ['using' => ['Busy Officer'], 'signed_in' => ['Visiting Officer'], 'none' => ['Absent Officer']],
            $report['groups'],
        );
        $this->assertSame(['Absent Officer', 'Visiting Officer', 'Busy Officer', 'Former Officer'], array_column($report['staff'], 'name'), 'Accounts needing attention come first.');
    }

    public function test_signing_in_and_exporting_a_report_are_logged_for_the_usage_report(): void
    {
        $this->grantToRole(Permissions::CRS, 'reports.view');
        $user = User::factory()->create(['email' => 'officer@bmpc.coop', 'password' => 'secret-pass-1', 'is_active' => true])->assignRole(Permissions::CRS);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'officer@bmpc.coop', 'password' => 'secret-pass-1'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        Livewire::test(Reports::class)->call('export', 'csv')->assertFileDownloaded();

        $this->assertSame(
            [['login', 'Sign-in'], ['report_exported', 'Reports']],
            AuditLog::where('user_id', $user->id)->orderBy('id')->get()->map(fn (AuditLog $log) => [$log->action, $log->module])->all(),
        );
    }

    public function test_a_developer_account_sees_the_usage_page_and_downloads_it_as_a_pdf(): void
    {
        $admin = $this->actingAsDeveloper();
        $admin->recordActivity('login', 'Sign-in');
        $idle = $this->staff('Idle Officer');

        $this->get(SystemUsage::getUrl())->assertOk();

        Livewire::test(SystemUsage::class)
            ->assertSeeInOrder(['50%', '1 of 2 staff accounts used the system', 'Needs follow-up:', 'Idle Officer'])
            ->assertSee('Using the system')
            ->assertSee('Not using the system')
            ->assertSeeInOrder(['Staff activity', 'Idle Officer', 'Nothing recorded in this period.', $admin->name, 'Signed in once.'])
            ->call('switchPeriod', 'last_month')
            ->assertSet('period', 'last_month')
            ->assertSeeInOrder(['0%', '0 of 2 staff accounts used the system'])
            ->callAction('downloadPdf')
            ->assertFileDownloaded();
    }

    public function test_accounts_switched_off_for_the_usage_report_are_neither_counted_nor_listed(): void
    {
        $admin = $this->actingAsDeveloper();
        $developer = User::factory()->create(['name' => 'Developer Account', 'is_active' => true, 'in_usage_report' => false])
            ->assignRole(Permissions::ADMIN);
        $developer->recordActivity('login', 'Sign-in');

        $report = app(StaffUsageReport::class)->build('this_week');

        $this->assertSame([$admin->id], array_keys($report['staff']));
        $this->assertSame([1, 0], [$report['summary']['accounts'], $report['summary']['sign_ins']]);

        Livewire::test(SystemUsage::class)
            ->assertSee($admin->name)
            ->assertDontSee('Developer Account');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesWithoutTheUsageReport(): array
    {
        return [
            'the cooperative administrator' => [Permissions::ADMIN],
            'a crs officer' => [Permissions::CRS],
        ];
    }

    #[DataProvider('rolesWithoutTheUsageReport')]
    public function test_only_developer_accounts_can_open_or_see_the_usage_report(string $role): void
    {
        $this->actingAsRole($role);

        $this->get(SystemUsage::getUrl())->assertForbidden();
        $this->get('/admin')->assertOk()->assertDontSee('System Usage');
    }

    public function test_only_a_developer_account_can_see_and_set_the_developer_switches_on_a_staff_account(): void
    {
        $officer = $this->staff('Branch Officer');
        $this->actingAsRole(Permissions::ADMIN);

        $this->get(UserResource::getUrl('edit', ['record' => $officer]))->assertForbidden();

        $this->actingAsDeveloper();

        Livewire::test(EditUser::class, ['record' => $officer->getRouteKey()])
            ->assertFormFieldIsVisible('is_developer')
            ->fillForm(['is_developer' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($officer->refresh()->is_developer);
    }
}
