<?php

namespace Tests\Feature\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\MemberResource\Pages\ListMembers;
use App\Models\Branch;
use App\Models\Member;
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
            ->callAction('export', ['format' => 'csv'])
            ->assertFileDownloaded('members-2026-06-30-080000.csv');

        $csv = base64_decode($component->effects['download']['content']);

        $this->assertStringContainsString('00101000001', $csv);
        $this->assertStringContainsString('1500.5', $csv);
        $this->assertStringNotContainsString('00101000002', $csv);
        $this->assertStringNotContainsString('00101000003', $csv);
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

    public function test_beneficiary_count_filter_finds_members_without_beneficiaries(): void
    {
        $without = Member::factory()->create();
        $with = Member::factory()->create();
        $with->beneficiaries()->create(['full_name' => 'Ana', 'relationship' => 'Child', 'share_percentage' => 100, 'is_active' => true]);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListMembers::class)
            ->loadTable()
            ->filterTable('beneficiaries', '0')
            ->assertCanSeeTableRecords([$without])
            ->assertCanNotSeeTableRecords([$with]);
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

    public function test_export_and_import_actions_follow_permissions(): void
    {
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListMembers::class)
            ->loadTable()
            ->assertActionVisible('import')
            ->assertActionHidden('export');
    }
}
