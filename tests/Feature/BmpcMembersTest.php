<?php

namespace Tests\Feature;

use App\Filament\Resources\ApplicationResource\Pages\ListApplications;
use App\Filament\Resources\BmpcMemberResource;
use App\Filament\Resources\BmpcMemberResource\Pages\ListBmpcMembers;
use App\Models\Application;
use App\Models\BmpcMember;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Access\Permissions;
use App\Services\Masterlist\BmpcMemberImportService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRole;
use Tests\Concerns\CreatesMasterlistWorkbooks;
use Tests\TestCase;

class BmpcMembersTest extends TestCase
{
    use ActsAsRole, CreatesMasterlistWorkbooks, LazilyRefreshDatabase;

    private function branchId(string $name): int
    {
        return Branch::where('name', $name)->value('id');
    }

    /**
     * The coop's member list as staff export it: a title row, the header, extra columns the system does not need.
     *
     * @param  list<list<mixed>>  $rows
     */
    private function memberList(array $rows): string
    {
        return $this->writeMasterlistWorkbook(tempnam(sys_get_temp_dir(), 'bmpc').'.xlsx', [
            'Members' => [
                ['BARBAZA MULTI-PURPOSE COOPERATIVE — MEMBER LIST'],
                ['No.', 'CIF Key', 'Name of Member', 'Birthdate', 'Mobile No.', 'Share Capital', 'Savings Balance', 'Loan Balance'],
                ...$rows,
            ],
        ]);
    }

    public function test_the_import_keeps_only_the_member_details_and_skips_rows_without_an_account_number_or_name(): void
    {
        $this->actingAsRole(Permissions::ADMIN);

        $summary = app(BmpcMemberImportService::class)->import($this->memberList([
            [1, '00101959720', 'DELA CRUZ, JUAN A.', '3/15/1980', '9171234567', 12000, 5300.5, 25000],
            [2, '00901959721', 'SANTOS, MARIA B.', '', '', 5000, 800, 0],
            [3, '', 'NO ACCOUNT, ROW', '', '', 0, 0, 0],
            [4, '00101959722', '', '', '', 0, 0, 0],
        ]));

        $this->assertSame(['added' => 2, 'updated' => 0, 'skipped' => 2, 'other_branch' => 0, 'sheets' => 1], $summary);

        $juan = BmpcMember::where('account_no', '00101959720')->sole();
        $this->assertSame(['Dela Cruz', 'Juan', 'A.'], [$juan->last_name, $juan->first_name, $juan->middle_name]);
        $this->assertSame('1980-03-15', $juan->birthdate->toDateString());
        $this->assertSame('09171234567', $juan->contact_number);
        $this->assertSame('5300.50', $juan->savings_balance);
        $this->assertSame($this->branchId('Barbaza'), $juan->branch_id, 'The first three digits are the branch code.');
        $this->assertSame($this->branchId('Kalibo'), BmpcMember::where('account_no', '00901959721')->value('branch_id'));
    }

    public function test_the_member_system_csv_export_is_read_with_its_quoted_cif_keys_origin_branch_and_phone_columns(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $path = tempnam(sys_get_temp_dir(), 'bmpc').'.csv';
        file_put_contents($path, "\u{FEFF}".implode("\n", [
            'CIFKEY,"MEMBER NAME","BIRTH DATE",AGE,ADDRESS,"TELEPHONE #","CONTACT #",SEX,"CIVIL STATUS",STATUS,"ORIGIN BRANCH"',
            '"=""0120000061447""","ESTACION, JASON C.",1994-07-10,32,"IBACA, PRESIDENT ROXAS, CAPIZ",09510662611,,MALE,SINGLE,Active,Sara',
            '"=""TEMP-1783478866511""","DE LA CRUZ, JOHN EZRA A.",,,,09949528143,09701882881,,,INCOMPLETE,Balasan',
            '"=""00400000SN8168.""","SALOMON, JOY C.",,,,,,FEMALE,,Active,"Barbaza Main"',
            '"=""01""","DALUMPINES, DEOLITA M.",,,,,,,,Active,Barbaza',
            '{',
            '    "message": "Server Error"',
            '}',
        ]));

        $summary = app(BmpcMemberImportService::class)->import($path);

        $this->assertSame([3, 4], [$summary['added'], $summary['skipped']], 'The too-short CIF Key and the three lines of the error message are skipped.');
        $this->assertSame(
            [
                '00400000SN8168' => [$this->branchId('Barbaza'), null],
                '0120000061447' => [$this->branchId('Sara'), '09510662611'],
                'TEMP-1783478866511' => [$this->branchId('Balasan'), '09701882881'],
            ],
            BmpcMember::orderBy('account_no')->get()->mapWithKeys(fn (BmpcMember $member) => [$member->account_no => [$member->branch_id, $member->contact_number]])->all(),
            'The branch comes from ORIGIN BRANCH, and CONTACT # is preferred over TELEPHONE #.',
        );
    }

    public function test_the_coop_list_brings_last_transaction_date_segmentation_category_and_remarks_or_leaves_them_empty(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $path = tempnam(sys_get_temp_dir(), 'bmpc').'.csv';
        file_put_contents($path, implode("\n", [
            'CIFKey,"Member Name","Birth Date",Branch,"Savings Balance","Last Transaction Date",Category,Classification,"Segmentation Status","Membership Status"',
            '0050000043123,"MOLINA, ZAIRAH G",2005-05-13,BALASAN,620.79,2026-08-15,COLISAP60000,NON-MIGS,SILVER,ACTIVE',
            '0050000043100,"TACURDA, ERDITA O",,BALASAN,,,,MIGS,"NOT SEGMENTED",DECEASED',
            '0050000043098,"ALAJAR, ROGEN D",,BALASAN,15,,,NON-MIGS,"NOT SEGMENTED",DORMANT-BAL',
        ]));

        app(BmpcMemberImportService::class)->import($path);

        $this->assertSame(
            [
                '0050000043098' => [null, null, null, 'dormant-bal'],
                '0050000043100' => [null, null, null, 'deceased'],
                '0050000043123' => ['2026-08-15', 'S', '60000', null],
            ],
            BmpcMember::orderBy('account_no')->get()->mapWithKeys(fn (BmpcMember $member) => [$member->account_no => [
                $member->last_transaction_date?->toDateString(), $member->segment, $member->category, $member->remarks,
            ]])->all(),
            'Segmentation comes from "Segmentation Status", not "Classification" (MIGS); what the file lacks stays empty.',
        );
    }

    public function test_staff_add_a_member_by_hand_from_the_add_button(): void
    {
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListBmpcMembers::class)
            ->assertActionVisible('create')
            ->callAction('create', data: [
                'account_no' => '00101959799', 'last_name' => 'Santos', 'first_name' => 'Maria', 'middle_name' => 'Reyes',
                'branch_id' => $this->branchId('Barbaza'), 'birthdate' => '1990-01-15', 'sex' => 'female', 'savings_balance' => 2500,
                'last_transaction_date' => '2026-10-01', 'segment' => 'G', 'category' => '40000',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Member added');

        $member = BmpcMember::sole();
        $this->assertSame(['SANTOS, MARIA REYES', 'G', '40000', '2026-10-01', null], [
            $member->account_name, $member->segment, $member->category, $member->last_transaction_date->toDateString(), $member->remarks,
        ], 'The Account Name is made from the name when none is typed; empty fields stay empty.');

        Livewire::test(ListBmpcMembers::class)
            ->callAction('create', data: ['account_no' => '00101959799', 'last_name' => 'Cruz', 'first_name' => 'Ana'])
            ->assertHasActionErrors(['account_no' => 'unique']);
    }

    public function test_importing_again_updates_members_without_erasing_details_the_new_file_leaves_blank(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $import = app(BmpcMemberImportService::class);
        $import->import($this->memberList([[1, '00101959720', 'DELA CRUZ, JUAN A.', '3/15/1980', '09171234567', 0, 5300, 0]]));

        $summary = $import->import($this->memberList([
            [1, '00101959720', 'DELA CRUZ, JUAN A.', '', '', 0, 6100, 0],
            [2, '00101959723', 'REYES, PEDRO', '', '', 0, 900, 0],
        ]));

        $this->assertSame([1, 1], [$summary['added'], $summary['updated']]);
        $juan = BmpcMember::where('account_no', '00101959720')->sole();
        $this->assertSame(['6100.00', '09171234567', '1980-03-15'], [$juan->savings_balance, $juan->contact_number, $juan->birthdate->toDateString()]);
        $this->assertSame(2, BmpcMember::count());
    }

    public function test_a_branch_officer_imports_and_sees_only_their_own_branch(): void
    {
        $officer = User::factory()->create(['is_active' => true, 'branch_id' => $this->branchId('Barbaza')])->assignRole(Permissions::CRS);
        $this->actingAs($officer);

        $summary = app(BmpcMemberImportService::class)->import($this->memberList([
            [1, '00101959720', 'DELA CRUZ, JUAN A.', '', '', 0, 5300, 0],
            [2, '00901959721', 'SANTOS, MARIA B.', '', '', 0, 800, 0],
        ]));

        $this->assertSame([1, 1], [$summary['added'], $summary['other_branch']]);

        $kalibo = BmpcMember::factory()->create(['branch_id' => $this->branchId('Kalibo')]);

        Livewire::test(ListBmpcMembers::class)
            ->assertCanSeeTableRecords(BmpcMember::where('account_no', '00101959720')->get())
            ->assertCanNotSeeTableRecords([$kalibo]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function staffRoles(): array
    {
        return ['the administrator' => [Permissions::ADMIN], 'a crs officer' => [Permissions::CRS]];
    }

    #[DataProvider('staffRoles')]
    public function test_staff_upload_the_member_list_from_the_import_button_and_it_goes_straight_in(string $role): void
    {
        Storage::fake('local');
        $this->actingAsRole($role);
        $this->get(BmpcMemberResource::getUrl('index'))->assertOk()->assertSee('Import Excel');

        $upload = UploadedFile::fake()
            ->createWithContent('members.xlsx', file_get_contents($this->memberList([[1, '00101959720', 'DELA CRUZ, JUAN A.', '', '', 0, 5300, 0]])))
            ->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $page = Livewire::test(ListBmpcMembers::class)
            ->callAction('import', data: ['file' => $upload])
            ->assertHasNoActionErrors()
            ->assertSee('Importing BMPC members');

        while ($page->get('importFile') !== null) {
            $page->call('importNextSheet');
        }

        $page->assertNotified('1 added, 0 updated')->assertDontSee('Importing BMPC members');

        $this->assertDatabaseHas('bmpc_members', ['account_no' => '00101959720', 'last_name' => 'Dela Cruz']);
        $this->assertSame([], Storage::disk('local')->allFiles('bmpc-member-imports'), 'The uploaded file is not kept.');

        $batch = ImportBatch::sole();
        $this->assertSame(['bmpc_members', 'members.xlsx', 'completed', 1, 0], [$batch->type, $batch->file_name, $batch->status, $batch->created_count, $batch->updated_count]);
        $this->assertSame($batch->id, BmpcMember::where('account_no', '00101959720')->value('import_batch_id'), 'Import History knows which members this upload added.');
    }

    public function test_the_view_window_opens_in_the_browser_with_the_details_in_four_columns(): void
    {
        $this->actingAsRole(Permissions::CRS);
        $member = BmpcMember::factory()->create([
            'account_no' => '010J00009', 'account_name' => 'AARON, ABNER R', 'address' => 'BRGY. JIBOLO, JANIUAY ILOILO', 'contact_number' => null,
            'birthdate' => now()->subYears(39)->subDay(), 'segment' => null, 'remarks' => 'dormant-txn',
        ]);

        $details = BmpcMemberResource::viewDetails($member);

        $this->assertSame('AARON, ABNER R', $details['title']);
        $this->assertSame([['Acct. Number', '010J00009'], ['Account Name', 'AARON, ABNER R']], $details['rows'][0], 'Two label / value pairs per table row.');
        $this->assertSame(['Age', '39'], $details['rows'][4][1], 'The age is worked out from the birthdate.');
        $this->assertSame(['Contact number', '—'], $details['rows'][5][0]);
        $this->assertSame(['Segmentation', 'NA'], $details['rows'][6][1]);
        $this->assertSame(['Remarks', 'Dormant-txn'], $details['rows'][7][1]);
        $this->assertSame([['Address', 'BRGY. JIBOLO, JANIUAY ILOILO']], $details['rows'][8], 'The address has a row of its own.');
        $this->assertTrue($details['canEdit']);

        Livewire::test(ListBmpcMembers::class)
            ->assertSeeHtml('bmpc-member-view')
            ->assertSeeHtml('colisap-details-table')
            ->assertTableActionVisible('view', $member)
            ->assertSee('010J00009');
    }

    #[DataProvider('staffRoles')]
    public function test_staff_correct_a_members_details_but_never_the_account_number(string $role): void
    {
        $this->actingAsRole($role);
        $member = BmpcMember::factory()->create(['account_no' => '00101959720', 'last_name' => 'Dela Cruz', 'contact_number' => null]);

        Livewire::test(ListBmpcMembers::class)
            ->assertTableActionVisible('edit', $member)
            ->callTableAction('edit', $member, data: ['account_no' => '99999999999', 'last_name' => 'Dela Cruz-Reyes', 'contact_number' => '09171234567'])
            ->assertHasNoTableActionErrors();

        $member->refresh();
        $this->assertSame(['00101959720', 'Dela Cruz-Reyes', '09171234567'], [$member->account_no, $member->last_name, $member->contact_number]);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => BmpcMember::class, 'auditable_id' => $member->id, 'action' => 'updated']);
    }

    public function test_the_view_window_has_an_edit_button(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $member = BmpcMember::factory()->create(['first_name' => 'Juan']);

        // The View window's Edit button runs mountTableAction('edit', key) for the member it shows.
        Livewire::test(ListBmpcMembers::class)
            ->assertSeeHtml("\$wire.mountTableAction('edit', member.key)")
            ->call('mountTableAction', 'edit', BmpcMemberResource::viewDetails($member)['key'])
            ->assertSet('mountedTableActions', ['edit'])
            ->assertTableActionDataSet(['first_name' => 'Juan'])
            ->setTableActionData(['first_name' => 'Pedro'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame('Pedro', $member->refresh()->first_name);
    }

    public function test_picking_a_bmpc_member_fills_in_the_new_transaction(): void
    {
        $this->actingAsRole(Permissions::CRS);
        $bmpcMember = BmpcMember::factory()->create([
            'account_no' => '00101959720', 'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'A.',
            'branch_id' => $this->branchId('Barbaza'), 'birthdate' => '1980-03-15', 'contact_number' => '09171234567', 'savings_balance' => 5300,
        ]);

        Livewire::test(ListApplications::class)
            ->mountAction('create')
            ->assertFormFieldIsVisible('bmpc_member_id', 'mountedActionForm')
            ->setActionData(['type' => 'new'])
            ->setActionData(['bmpc_member_id' => $bmpcMember->getKey()])
            ->assertActionDataSet([
                'account_no' => '00101959720', 'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'A.',
                'branch_id' => $this->branchId('Barbaza'), 'birthdate' => '1980-03-15', 'contact_number' => '09171234567',
            ])
            ->setActionData(['application_date' => now()->toDateString(), 'category' => '40000'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $application = Application::sole();
        $this->assertSame(['00101959720', 'Dela Cruz', 'Juan', '5300.00'], [$application->account_no, $application->last_name, $application->first_name, (string) $application->savings_balance]);
    }

    public function test_someone_not_on_the_bmpc_list_is_typed_in_by_hand_and_upgrades_have_no_lookup(): void
    {
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListApplications::class)
            ->mountAction('create')
            ->setActionData(['type' => 'upgrade'])
            ->assertFormFieldIsHidden('bmpc_member_id', 'mountedActionForm')
            ->setActionData(['type' => 'new'])
            ->assertFormFieldIsVisible('bmpc_member_id', 'mountedActionForm')
            ->setActionData([
                'account_no' => '00101777200', 'last_name' => 'Garcia', 'first_name' => 'Lito', 'branch_id' => $this->branchId('Barbaza'),
                'birthdate' => '1985-01-01', 'savings_balance' => 5000, 'application_date' => now()->toDateString(), 'category' => '40000',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('Garcia', Application::sole()->last_name);
    }
}
