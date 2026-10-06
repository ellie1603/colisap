<?php

namespace Tests\Feature\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\MemberResource\Pages\ListMembers;
use App\Models\Branch;
use App\Models\Member;
use App\Models\User;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class ListMembersTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    public function test_export_downloads_only_members_matching_filters_with_leading_zeros(): void
    {
        $this->travelTo('2026-06-30 08:00:00');
        $barbaza = Branch::where('name', 'Barbaza')->value('id');
        Member::factory()->dormant()->create(['account_no' => '00101000001', 'branch_id' => $barbaza, 'savings_balance' => 1500.5]);
        Member::factory()->create(['account_no' => '00101000002', 'branch_id' => $barbaza]);
        Member::factory()->dormant()->create(['account_no' => '00101000003', 'branch_id' => Branch::where('name', 'Kalibo')->value('id')]);
        $this->actingAsRole(Permissions::ADMIN);

        $component = Livewire::test(ListMembers::class)
            ->loadTable()
            ->filterTable('status', ['dormant'])
            ->filterTable('branch_id', [$barbaza])
            ->callAction('export', ['layout' => 'detailed', 'format' => 'csv'])
            ->assertFileDownloaded('members-2026-06-30-080000.csv');

        $csv = base64_decode($component->effects['download']['content']);

        $this->assertStringContainsString('00101000001', $csv);
        $this->assertStringContainsString('1500.5', $csv);
        $this->assertStringNotContainsString('00101000002', $csv);
        $this->assertStringNotContainsString('00101000003', $csv);
    }

    public function test_organized_export_follows_the_colisap_masterlist_template(): void
    {
        $this->travelTo('2026-06-30 08:00:00');
        Member::factory()->segment('G')->category60000()->create([
            'account_no' => '00101000001', 'account_name' => 'DELA CRUZ, JUAN', 'savings_balance' => 2500,
            'branch_id' => Branch::where('name', 'Kalibo')->value('id'), 'approval_date' => '2024-01-15', 'application_date' => null,
        ]);
        Member::factory()->create(['account_no' => '00101000002', 'account_name' => 'ABAD, ANA', 'branch_id' => Branch::where('name', 'Barbaza')->value('id')]);
        $this->actingAsRole(Permissions::CRS);

        $component = Livewire::test(ListMembers::class)
            ->loadTable()
            ->callAction('export', ['layout' => 'organized', 'format' => 'csv'])
            ->assertFileDownloaded('colisap-masterlist-2026-06-30.csv');

        $csv = preg_replace('/^\xEF\xBB\xBF/', '', base64_decode($component->effects['download']['content']));
        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", $csv))));

        $this->assertSame(['BARBAZA MULTI-PURPOSE COOPERATIVE'], $lines[0]);
        $this->assertSame(['MEMBERSHIP MASTERLIST'], $lines[2]);
        $this->assertSame(['CIF Key', 'Account Name', 'Branch', 'Segmentation', 'Category (40,000 / 60,000)', 'Application Date', 'Status', 'Saving Balance'], $lines[4]);
        $this->assertSame(['00101000002', '00101000001'], [$lines[5][0], $lines[6][0]], 'Sorted by branch order (Barbaza before Kalibo).');
        $this->assertSame(['00101000001', 'DELA CRUZ, JUAN', 'Kalibo', 'Gold', '60000', '2024-01-15', 'Active', '2500'], $lines[6]);
    }

    public function test_status_tabs_and_segment_filter_narrow_the_list(): void
    {
        $diamond = Member::factory()->segment('D')->create();
        $unassigned = Member::factory()->segment(null)->create();
        $waiting = Member::factory()->waiting()->create();
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListMembers::class)
            ->loadTable()
            ->set('activeTab', 'waiting')
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$diamond, $unassigned]);

        Livewire::test(ListMembers::class)
            ->loadTable()
            ->filterTable('segment', ['none'])
            ->assertCanSeeTableRecords([$unassigned])
            ->assertCanNotSeeTableRecords([$diamond]);
    }

    public function test_search_matches_account_number_and_full_name(): void
    {
        $juan = Member::factory()->create(['account_no' => '00101959720', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'account_name' => 'DELA CRUZ, JUAN']);
        $other = Member::factory()->create(['first_name' => 'Juana', 'last_name' => 'Reyes', 'account_name' => 'REYES, JUANA']);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListMembers::class)
            ->loadTable()
            ->searchTable('juan dela cruz')
            ->assertCanSeeTableRecords([$juan])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListMembers::class)
            ->loadTable()
            ->searchTable('00101959720')
            ->assertCanSeeTableRecords([$juan])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_archived_members_can_be_restored_by_admin_only(): void
    {
        $member = Member::factory()->create();
        $member->delete();

        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(ListMembers::class)
            ->loadTable()
            ->assertCanNotSeeTableRecords([$member])
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$member])
            ->callTableAction('restore', $member);

        $this->assertFalse($member->fresh()->trashed());
    }

    public function test_selected_members_can_be_deleted_by_an_administrator_and_stay_restorable(): void
    {
        [$wrong, $alsoWrong, $kept] = Member::factory()->count(3)->create()->all();

        $this->actingAsRole(Permissions::CRS);
        Livewire::test(ListMembers::class)->loadTable()->assertTableBulkActionHidden('delete');

        $this->actingAsRole(Permissions::ADMIN);
        Livewire::test(ListMembers::class)
            ->loadTable()
            ->assertTableBulkActionVisible('delete')
            ->callTableBulkAction('delete', [$wrong, $alsoWrong])
            ->assertCanNotSeeTableRecords([$wrong, $alsoWrong])
            ->assertCanSeeTableRecords([$kept]);

        $this->assertSoftDeleted($wrong);
        $this->assertSoftDeleted($alsoWrong);
        $this->assertFalse($kept->fresh()->trashed());
    }

    public function test_export_and_import_actions_follow_permissions(): void
    {
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListMembers::class)
            ->loadTable()
            ->assertActionVisible('import')
            ->assertActionVisible('export');

        $this->actingAs(User::factory()->create(['is_active' => true])->givePermissionTo('members.view'));

        Livewire::test(ListMembers::class)
            ->loadTable()
            ->assertActionHidden('import')
            ->assertActionHidden('export');
    }
}
