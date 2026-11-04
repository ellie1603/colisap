<?php

namespace Tests\Feature;

use App\Filament\Resources\ApplicationResource\Pages\ListApplications;
use App\Filament\Resources\BmpcMemberResource;
use App\Filament\Resources\BmpcMemberResource\Pages\ListBmpcMembers;
use App\Filament\Resources\BmpcMemberResource\Widgets\BmpcMemberStats;
use App\Models\Application;
use App\Models\BmpcMember;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\Member;
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
     * The coop's member list as staff export it: a title row, the header with the twelve details, and a column the
     * system does not need (Loan Balance).
     *
     * @param  list<array<string, mixed>>  $rows  each keyed by header; a missing header is a blank cell
     */
    private function memberList(array $rows): string
    {
        $headers = ['Savings Number', 'CIF Key', 'Account Name', 'Branch', 'Birthdate', 'Contact Number', 'Savings Balance', 'Last Transaction Date', 'Segmentation', 'Category', 'Status', 'Remarks', 'Loan Balance'];

        return $this->writeMasterlistWorkbook(tempnam(sys_get_temp_dir(), 'bmpc').'.xlsx', [
            'Members' => [
                ['BARBAZA MULTI-PURPOSE COOPERATIVE — MEMBER LIST'],
                $headers,
                ...array_map(fn (array $row) => array_map(fn (string $header) => $row[$header] ?? '', $headers), $rows),
            ],
        ]);
    }

    public function test_the_import_keeps_the_twelve_details_and_skips_rows_without_a_cif_key_or_account_name(): void
    {
        $this->actingAsRole(Permissions::ADMIN);

        $summary = app(BmpcMemberImportService::class)->import($this->memberList([
            [
                'Savings Number' => '00101959720', 'CIF Key' => '0000006392', 'Account Name' => 'DELA CRUZ, JUAN A.', 'Birthdate' => '3/15/1980',
                'Contact Number' => '9171234567', 'Savings Balance' => 5300.5, 'Last Transaction Date' => '2026-08-15', 'Segmentation' => 'Gold',
                'Category' => '40000', 'Status' => 'Active', 'Remarks' => 'Dormant-txn', 'Loan Balance' => 25000,
            ],
            ['Savings Number' => '00901959721', 'CIF Key' => '0000006393', 'Account Name' => 'SANTOS, MARIA B.', 'Branch' => '002'],
            ['CIF Key' => '0000006394', 'Account Name' => 'REYES, ANA', 'Branch' => 'San Jose'],
            ['Savings Number' => '00201959725', 'CIF Key' => '0000006396', 'Account Name' => ', JEAN A NARCISO KIS'],
            ['Savings Number' => '00101959723', 'Account Name' => 'NO CIF, ROW'],
            ['Savings Number' => '00101959724', 'CIF Key' => '0000006395'],
        ]));

        $this->assertSame(['added' => 4, 'updated' => 0, 'skipped' => 2, 'other_branch' => 0, 'sheets' => 1], $summary);

        $juan = BmpcMember::where('cif_key', '0000006392')->sole();
        $this->assertSame(
            ['00101959720', 'DELA CRUZ, JUAN A.', '1980-03-15', '09171234567', '5300.50', '2026-08-15', 'G', '40000', 'Active', 'Dormant-txn'],
            [$juan->savings_no, $juan->account_name, $juan->birthdate->toDateString(), $juan->contact_number, $juan->savings_balance,
                $juan->last_transaction_date->toDateString(), $juan->segment, $juan->category, $juan->status, $juan->remarks],
            'Status and Remarks are kept as the file writes them.',
        );
        $this->assertSame(['Dela Cruz', 'Juan', 'A.'], [$juan->last_name, $juan->first_name, $juan->middle_name], 'The name parts come from the Account Name.');
        $this->assertSame($this->branchId('Barbaza'), $juan->branch_id, 'Without a Branch, the savings number\'s first three digits are the branch code.');
        $this->assertSame($this->branchId('Culasi'), BmpcMember::where('cif_key', '0000006393')->value('branch_id'), 'A branch code in the Branch column.');
        $this->assertSame($this->branchId('San Jose'), BmpcMember::where('cif_key', '0000006394')->value('branch_id'), 'A branch name in the Branch column.');
        $this->assertSame('NARCISO KIS, JEAN A.', BmpcMember::where('cif_key', '0000006396')->value('account_name'), 'A name with a blank surname part is put surname first.');
    }

    public function test_the_savings_system_export_has_its_savings_number_joined_from_br_grp_and_acct_no(): void
    {
        Storage::fake('local');
        $this->actingAsRole(Permissions::ADMIN);
        // As the savings system exports it: its "Savings Number" is a formula saved without the result.
        $path = $this->writeMasterlistWorkbook(tempnam(sys_get_temp_dir(), 'bmpc').'.xlsx', [
            'Savings' => [
                ['Br', 'Grp', 'AcctNo.', 'AccountName', 'LastMoveDate', 'LastOTCDate', 'ActiveBalance', 'DrmtBalance', 'CIF Key', '', 'Savings Number'],
                ['002', '01', '000016', 'NAVATO, APRIL MAE FIGUEROA', '2026-09-30', '2023-11-14', 5000, 0, '0020000023386', '', '=CONCAT(A2:C2)'],
                [2, 1, 41, 'BALLENAS, ALEX KITS N', '2026-09-30', '2023-05-24', 800, 0, '0020000019986', '', '='],
            ],
        ]);
        $upload = UploadedFile::fake()->createWithContent('002 CULASI-SD 2026.xlsx', file_get_contents($path))->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $page = Livewire::test(ListBmpcMembers::class)
            ->callAction('import', data: ['file' => $upload])
            ->assertActionDataSet([
                'savings_no' => BmpcMemberImportService::BUILT_SAVINGS_NO, 'cif_key' => 'CIF Key', 'account_name' => 'AccountName',
                'branch' => 'Br', 'savings_balance' => 'ActiveBalance', 'last_transaction_date' => 'LastOTCDate',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        while ($page->get('importFile') !== null) {
            $page->call('importNextSheet');
        }

        $this->assertSame(
            ['0020000019986' => ['00201000041', $this->branchId('Culasi'), '2023-05-24'], '0020000023386' => ['00201000016', $this->branchId('Culasi'), '2023-11-14']],
            BmpcMember::orderBy('cif_key')->get()->mapWithKeys(fn (BmpcMember $member) => [$member->cif_key => [$member->savings_no, $member->branch_id, $member->last_transaction_date->toDateString()]])->all(),
            'The zeros Excel drops from number cells are put back.',
        );

        // Picking the formula column itself never saves the formula.
        $sheets = app(BmpcMemberImportService::class)->applyMapping(app(BmpcMemberImportService::class)->mappableSheets($path), ['savings_no' => 'Savings Number', 'cif_key' => 'CIF Key', 'account_name' => 'AccountName']);
        $summary = BmpcMemberImportService::emptySummary();
        app(BmpcMemberImportService::class)->importSheet($path, $sheets[0], $summary);
        $this->assertSame(['00201000016', '00201000041'], BmpcMember::orderBy('cif_key', 'desc')->pluck('savings_no')->all(), 'A blank (formula) savings number keeps the one on file.');
    }

    public function test_a_branch_is_found_by_its_code_or_its_name(): void
    {
        $culasi = $this->branchId('Culasi');

        foreach (['002', '2', '002 - Culasi', 'Culasi', 'CULASI BRANCH'] as $text) {
            $this->assertSame($culasi, Branch::resolve($text)?->id, "\"{$text}\" is Culasi.");
        }

        $this->assertSame($this->branchId('President Roxas'), Branch::resolve('13')?->id);
        $this->assertNull(Branch::resolve('2026'), 'A year is not a branch code.');
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
                '00400000SN8168' => [$this->branchId('Barbaza'), null, 'Active'],
                '0120000061447' => [$this->branchId('Sara'), '09510662611', 'Active'],
                'TEMP-1783478866511' => [$this->branchId('Balasan'), '09701882881', 'INCOMPLETE'],
            ],
            BmpcMember::orderBy('cif_key')->get()->mapWithKeys(fn (BmpcMember $member) => [$member->cif_key => [$member->branch_id, $member->contact_number, $member->status]])->all(),
            'The branch comes from ORIGIN BRANCH, and CONTACT # is preferred over TELEPHONE #.',
        );
    }

    public function test_the_coop_list_brings_last_transaction_date_segmentation_category_and_status_or_leaves_them_empty(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $path = tempnam(sys_get_temp_dir(), 'bmpc').'.csv';
        file_put_contents($path, implode("\n", [
            'CIFKey,"Member Name","Birth Date",Branch,"Savings Balance","Last Transaction Date",Category,Classification,"Segmentation Status","Membership Status"',
            '0050000043123,"MOLINA, ZAIRAH G",2005-05-13,BALASAN,620.79,2026-08-15,COLISAP60000,NON-MIGS,SILVER,ACTIVE',
            '0050000043100,"TACURDA, ERDITA O",,BALASAN,,,,MIGS,"NOT SEGMENTED",DECEASED',
        ]));

        app(BmpcMemberImportService::class)->import($path);

        $this->assertSame(
            [
                '0050000043100' => [null, null, null, 'DECEASED'],
                '0050000043123' => ['2026-08-15', 'S', '60000', 'ACTIVE'],
            ],
            BmpcMember::orderBy('cif_key')->get()->mapWithKeys(fn (BmpcMember $member) => [$member->cif_key => [
                $member->last_transaction_date?->toDateString(), $member->segment, $member->category, $member->status,
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
                'savings_no' => '00101959799', 'cif_key' => '0000007001', 'account_name' => 'santos, maria reyes',
                'branch_id' => $this->branchId('Barbaza'), 'birthdate' => '1990-01-15', 'savings_balance' => 2500,
                'last_transaction_date' => '2026-10-01', 'segment' => 'G', 'category' => '40000', 'status' => 'Active',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Member added');

        $member = BmpcMember::sole();
        $this->assertSame(['00101959799', 'SANTOS, MARIA REYES', 'Santos', 'Active', null], [
            $member->savings_no, $member->account_name, $member->last_name, $member->status, $member->remarks,
        ], 'The name parts are worked out from the Account Name; empty fields stay empty.');

        Livewire::test(ListBmpcMembers::class)
            ->callAction('create', data: ['cif_key' => '0000007001', 'account_name' => 'CRUZ, ANA'])
            ->assertHasActionErrors(['cif_key' => 'unique']);
    }

    public function test_importing_again_updates_members_by_cif_key_without_erasing_details_the_new_file_leaves_blank(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $import = app(BmpcMemberImportService::class);
        $import->import($this->memberList([[
            'Savings Number' => '00101959720', 'CIF Key' => '0000006392', 'Account Name' => 'DELA CRUZ, JUAN A.', 'Birthdate' => '3/15/1980',
            'Contact Number' => '09171234567', 'Savings Balance' => 5300,
        ]]));

        $summary = $import->import($this->memberList([
            ['CIF Key' => '0000006392', 'Account Name' => 'DELA CRUZ, JUAN A.', 'Savings Balance' => 6100],
            ['CIF Key' => '0000006393', 'Account Name' => 'REYES, PEDRO', 'Savings Balance' => 900],
        ]));

        $this->assertSame([1, 1], [$summary['added'], $summary['updated']]);
        $juan = BmpcMember::where('cif_key', '0000006392')->sole();
        $this->assertSame(['00101959720', '6100.00', '09171234567', '1980-03-15'], [$juan->savings_no, $juan->savings_balance, $juan->contact_number, $juan->birthdate->toDateString()]);
        $this->assertSame(2, BmpcMember::count());
    }

    public function test_a_branch_officer_imports_and_sees_only_their_own_branch(): void
    {
        $officer = User::factory()->create(['is_active' => true, 'branch_id' => $this->branchId('Barbaza')])->assignRole(Permissions::CRS);
        $this->actingAs($officer);

        $summary = app(BmpcMemberImportService::class)->import($this->memberList([
            ['Savings Number' => '00101959720', 'CIF Key' => '0000006392', 'Account Name' => 'DELA CRUZ, JUAN A.'],
            ['Savings Number' => '00901959721', 'CIF Key' => '0000006393', 'Account Name' => 'SANTOS, MARIA B.'],
        ]));

        $this->assertSame([1, 1], [$summary['added'], $summary['other_branch']]);

        $kalibo = BmpcMember::factory()->create(['branch_id' => $this->branchId('Kalibo')]);

        Livewire::test(ListBmpcMembers::class)
            ->assertCanSeeTableRecords(BmpcMember::where('cif_key', '0000006392')->get())
            ->assertCanNotSeeTableRecords([$kalibo]);
    }

    public function test_the_cards_count_colisap_members_by_savings_number_and_remarks_of_the_filtered_list(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $barbaza = $this->branchId('Barbaza');
        $kalibo = $this->branchId('Kalibo');

        $enrolled = BmpcMember::factory()->create(['branch_id' => $barbaza]);
        $terminated = BmpcMember::factory()->create(['branch_id' => $barbaza]);
        BmpcMember::factory()->create(['branch_id' => $barbaza]);
        BmpcMember::factory()->create(['branch_id' => $barbaza, 'savings_balance' => 100]);
        BmpcMember::factory()->create(['branch_id' => $kalibo, 'remarks' => 'Dormant-txn']);
        Member::factory()->create(['account_no' => $enrolled->savings_no]);
        Member::factory()->terminated()->create(['account_no' => $terminated->savings_no]);
        Member::factory()->create(['account_no' => $terminated->cif_key]);

        Livewire::test(ListBmpcMembers::class)->assertSeeLivewire(BmpcMemberStats::class);

        $cardCounts = fn (array $cards): array => array_column($cards, 'count');

        Livewire::test(BmpcMemberStats::class)
            ->assertViewHas('total', 5)
            ->assertViewHas('cards', fn (array $cards) => $cardCounts($cards) === [1, 4, 1]);

        Livewire::test(BmpcMemberStats::class, ['tableFilters' => ['branch_id' => ['value' => $kalibo]]])
            ->assertViewHas('total', 1)
            ->assertViewHas('cards', fn (array $cards) => $cardCounts($cards) === [0, 1, 1]);
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
            ->createWithContent('members.xlsx', file_get_contents($this->memberList([
                ['Savings Number' => '00101959720', 'CIF Key' => '0000006392', 'Account Name' => 'DELA CRUZ, JUAN A.', 'Savings Balance' => 5300],
            ])))
            ->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $page = Livewire::test(ListBmpcMembers::class)
            ->callAction('import', data: ['file' => $upload])
            ->assertHasNoActionErrors()
            ->assertActionMounted('mapColumns')
            ->assertActionDataSet([
                'savings_no' => 'Savings Number', 'cif_key' => 'CIF Key', 'account_name' => 'Account Name', 'branch' => 'Branch',
                'birthdate' => 'Birthdate', 'contact_number' => 'Contact Number', 'savings_balance' => 'Savings Balance',
                'last_transaction_date' => 'Last Transaction Date', 'segment' => 'Segmentation', 'category' => 'Category',
                'status' => 'Status', 'remarks' => 'Remarks',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSee('Importing BMPC members');

        while ($page->get('importFile') !== null) {
            $page->call('importNextSheet');
        }

        $page->assertNotified('1 added, 0 updated')->assertDontSee('Importing BMPC members');

        $this->assertDatabaseHas('bmpc_members', ['cif_key' => '0000006392', 'savings_no' => '00101959720', 'last_name' => 'Dela Cruz']);
        $this->assertSame([], Storage::disk('local')->allFiles('bmpc-member-imports'), 'The uploaded file is not kept.');

        $batch = ImportBatch::sole();
        $this->assertSame(['bmpc_members', 'members.xlsx', 'completed', 1, 0], [$batch->type, $batch->file_name, $batch->status, $batch->created_count, $batch->updated_count]);
        $this->assertSame($batch->id, BmpcMember::where('cif_key', '0000006392')->value('import_batch_id'), 'Import History knows which members this upload added.');
    }

    public function test_staff_choose_which_column_each_detail_comes_from_before_the_import(): void
    {
        Storage::fake('local');
        $this->actingAsRole(Permissions::ADMIN);
        $upload = fn () => UploadedFile::fake()
            ->createWithContent('members.xlsx', file_get_contents($this->memberList([[
                'Savings Number' => '00101959720', 'CIF Key' => '0000006392', 'Account Name' => 'DELA CRUZ, JUAN A.',
                'Contact Number' => '9171234567', 'Savings Balance' => 5300, 'Loan Balance' => 12000,
            ]])))
            ->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $page = Livewire::test(ListBmpcMembers::class)
            ->callAction('import', data: ['file' => $upload()])
            ->setActionData(['cif_key' => null])
            ->callMountedAction()
            ->assertHasActionErrors(['cif_key' => 'required'])
            ->assertSet('importBatchId', null)
            ->setActionData(['cif_key' => 'CIF Key', 'savings_balance' => 'Loan Balance', 'contact_number' => null])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        while ($page->get('importFile') !== null) {
            $page->call('importNextSheet');
        }

        $member = BmpcMember::where('cif_key', '0000006392')->sole();
        $this->assertSame(['12000.00', null], [$member->savings_balance, $member->contact_number], 'The chosen column is read; a detail set to "not in file" stays empty.');

        Livewire::test(ListBmpcMembers::class)
            ->callAction('import', data: ['file' => $upload()])
            ->assertActionMounted('mapColumns')
            ->call('unmountAction')
            ->assertSet('importFile', null)
            ->assertSet('mountedActions', []);

        $this->assertSame([], Storage::disk('local')->allFiles('bmpc-member-imports'), 'Cancelling deletes the upload.');
        $this->assertSame(1, ImportBatch::count(), 'Nothing is logged for a cancelled upload.');
    }

    public function test_the_view_window_opens_in_the_browser_with_the_twelve_details(): void
    {
        $this->actingAsRole(Permissions::CRS);
        $member = BmpcMember::factory()->create([
            'savings_no' => '01000000J00009', 'cif_key' => '010J00009', 'account_name' => 'AARON, ABNER R', 'branch_id' => $this->branchId('Janiuay'),
            'contact_number' => null, 'segment' => null, 'status' => 'Active', 'remarks' => 'Dormant-txn',
        ]);

        $details = BmpcMemberResource::viewDetails($member);

        $this->assertSame('AARON, ABNER R', $details['title']);
        $this->assertSame([
            [['Savings number', '01000000J00009'], ['CIF Key', '010J00009']],
            [['Account Name', 'AARON, ABNER R'], ['Branch', '010 - Janiuay']],
        ], array_slice($details['rows'], 0, 2), 'Two label / value pairs per table row.');
        $this->assertSame(['Contact Number', '—'], $details['rows'][2][1]);
        $this->assertSame(['Segmentation', 'NA'], $details['rows'][4][0]);
        $this->assertSame([['Status', 'Active'], ['Remarks', 'Dormant-txn']], $details['rows'][5]);
        $this->assertCount(6, $details['rows']);
        $this->assertTrue($details['canEdit']);

        Livewire::test(ListBmpcMembers::class)
            ->assertSeeHtml('bmpc-member-view')
            ->assertSeeHtml('colisap-details-table')
            ->assertSeeHtml('data-row-view="data-row-view"')
            ->assertSeeHtml('colisap-clickable-rows')
            ->assertTableActionVisible('view', $member)
            ->assertSee('010J00009');
    }

    #[DataProvider('staffRoles')]
    public function test_staff_correct_a_members_details_but_never_the_cif_key(string $role): void
    {
        $this->actingAsRole($role);
        $member = BmpcMember::factory()->create(['cif_key' => '0000006392', 'account_name' => 'DELA CRUZ, JUAN', 'contact_number' => null]);

        Livewire::test(ListBmpcMembers::class)
            ->assertTableActionVisible('edit', $member)
            ->callTableAction('edit', $member, data: ['cif_key' => '9999999999', 'account_name' => 'DELA CRUZ-REYES, JUAN', 'contact_number' => '09171234567'])
            ->assertHasNoTableActionErrors();

        $member->refresh();
        $this->assertSame(['0000006392', 'DELA CRUZ-REYES, JUAN', 'Dela Cruz-Reyes', '09171234567'], [$member->cif_key, $member->account_name, $member->last_name, $member->contact_number]);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => BmpcMember::class, 'auditable_id' => $member->id, 'action' => 'updated']);
    }

    public function test_the_view_window_has_an_edit_button(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $member = BmpcMember::factory()->create(['account_name' => 'CRUZ, JUAN']);

        // The View window's Edit button runs mountTableAction('edit', key) for the member it shows.
        Livewire::test(ListBmpcMembers::class)
            ->assertSeeHtml("\$wire.mountTableAction('edit', member.key)")
            ->call('mountTableAction', 'edit', BmpcMemberResource::viewDetails($member)['key'])
            ->assertSet('mountedTableActions', ['edit'])
            ->assertTableActionDataSet(['account_name' => 'CRUZ, JUAN'])
            ->setTableActionData(['account_name' => 'CRUZ, PEDRO'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame('Pedro', $member->refresh()->first_name);
    }

    public function test_picking_a_bmpc_member_fills_in_the_new_transaction_with_their_savings_number_as_acct_number(): void
    {
        $this->actingAsRole(Permissions::CRS);
        $bmpcMember = BmpcMember::factory()->create([
            'savings_no' => '00101959720', 'cif_key' => '0000006392', 'account_name' => 'DELA CRUZ, JUAN A.', 'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'A.',
            'branch_id' => $this->branchId('Barbaza'), 'birthdate' => '1980-03-15', 'contact_number' => '09171234567', 'savings_balance' => 5300,
        ]);

        $this->assertSame([$bmpcMember->id], BmpcMember::query()->searchName('0000006392')->pluck('id')->all(), 'The lookup finds a member by CIF Key.');

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
