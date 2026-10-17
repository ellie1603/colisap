<?php

namespace Tests\Feature\Filament\Resources\MemberResource\Pages;

use App\Filament\Pages\ImportApprovals;
use App\Filament\Resources\MemberResource;
use App\Filament\Resources\MemberResource\Pages\ImportMembers;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\Member;
use App\Models\User;
use App\Services\Access\Permissions;
use App\Services\Masterlist\MasterlistImportService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\ActsAsRole;
use Tests\Concerns\CreatesMasterlistWorkbooks;
use Tests\TestCase;

class ImportMembersTest extends TestCase
{
    use ActsAsRole, CreatesMasterlistWorkbooks, LazilyRefreshDatabase;

    private function workbookUpload(): UploadedFile
    {
        $header = ['Remarks', 'Acct. Number', 'Account Name', 'Segmentation', 'Category 30K/50K', 'NEW Date of Approval', 'Savings Balance'];

        $path = $this->writeMasterlistWorkbook(tempnam(sys_get_temp_dir(), 'masterlist').'.xlsx', [
            'Barbaza' => [
                ['BARBAZA MULTI-PURPOSE COOPERATIVE'],
                $header,
                ['', '00101959720', 'DELA CRUZ, JUAN A.', 'G', 'COLISAP40000', '6/3/1998', 57030.04],
                ['D', '00101959721', 'SANTOS, MARIA B.', 'S', 'COLISAP40000', '6/3/1998', 800],
            ],
            'Pres Roxas' => [
                $header,
                ['', '00101959722', 'LOPEZ, ANA', '#N/A', 'COLISAP60000', '1/2/2020', 2500],
                ['', '', 'NO ACCOUNT, ROW', 'R', 'COLISAP40000', '1/2/2020', 100],
            ],
            'Notes' => [['Prepared by'], ['Ma\'am Amor']],
        ]);

        return UploadedFile::fake()->createWithContent('masterlist.xlsx', file_get_contents($path))
            ->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_administrator_imports_a_masterlist_through_every_step(): void
    {
        Storage::fake('local');
        $this->actingAsRole(Permissions::ADMIN);

        $page = Livewire::test(ImportMembers::class)
            ->set('upload.file', $this->workbookUpload())
            ->call('analyze');

        $batch = ImportBatch::sole();
        $this->assertTrue($batch->as_of_date->isToday(), 'Balances are dated to the upload day automatically.');
        $this->assertSame(['Barbaza', 'President Roxas', null], array_column($batch->sheets, 'branch_name'));
        $page->assertSet('mapping.2.include', false);

        $page->call('validateMapping')->assertSet('staging', true);

        while ($page->get('staging')) {
            $page->call('stageNext');
        }

        $batch->refresh();
        $this->assertSame(['staged', 3, 1], [$batch->status, $batch->new_rows, $batch->invalid_rows]);
        $page->assertSee('Ready to import')->assertCanSeeTableRecords($batch->rows()->where('action', '<>', 'reference')->get());

        $page->call('startImport');

        while ($batch->refresh()->status === 'importing') {
            $page->call('importNext');
        }

        $this->assertSame('completed', $batch->status);
        $this->assertSame([
            '00101959720' => 'active',
            '00101959721' => 'deceased',
            '00101959722' => 'active',
        ], Member::orderBy('account_no')->pluck('status', 'account_no')->all());
        $this->assertSame('President Roxas', Member::where('account_no', '00101959722')->first()->branch->name);
    }

    public function test_selected_rows_can_be_deleted_from_an_import_before_it_is_written(): void
    {
        Storage::fake('local');
        $this->actingAsRole(Permissions::ADMIN);

        $page = Livewire::test(ImportMembers::class)
            ->set('upload.file', $this->workbookUpload())
            ->call('analyze')
            ->call('validateMapping');

        while ($page->get('staging')) {
            $page->call('stageNext');
        }

        $batch = ImportBatch::sole();
        $unwanted = $batch->rows()->where('account_no', '00101959721')->sole();
        $invalid = $batch->rows()->where('action', 'invalid')->sole();

        $page->assertTableBulkActionVisible('remove')
            ->callTableBulkAction('remove', [$unwanted, $invalid])
            ->assertNotified('2 rows deleted from this import')
            ->assertCanNotSeeTableRecords([$unwanted, $invalid]);

        $batch->refresh();
        $this->assertSame([2, 0], [$batch->new_rows, $batch->invalid_rows]);

        $page->call('startImport');

        while ($batch->refresh()->status === 'importing') {
            $page->call('importNext');
        }

        $this->assertSame(['00101959720', '00101959722'], Member::orderBy('account_no')->pluck('account_no')->all());
        $page->assertTableBulkActionHidden('remove');
    }

    public function test_a_file_with_a_duplicate_account_number_is_blocked_until_the_duplicate_row_is_deleted(): void
    {
        Storage::fake('local');
        $this->actingAsRole(Permissions::ADMIN);
        $header = ['Acct. Number', 'Account Name', 'Category', 'NEW Date of Approval', 'Savings Balance'];
        $path = $this->writeMasterlistWorkbook(tempnam(sys_get_temp_dir(), 'duplicates').'.xlsx', [
            'Barbaza' => [
                $header,
                ['00101959720', 'DELA CRUZ, JUAN A.', 'COLISAP40000', '6/3/1998', 5000],
                ['00101959721', 'SANTOS, MARIA B.', 'COLISAP40000', '6/3/1998', 800],
                ['00101959720', 'DELA CRUZ, JUAN A.', 'COLISAP40000', '6/3/1998', 5000],
            ],
        ]);
        $upload = UploadedFile::fake()->createWithContent('masterlist.xlsx', file_get_contents($path))
            ->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $page = Livewire::test(ImportMembers::class)
            ->set('upload.file', $upload)
            ->call('analyze')
            ->call('validateMapping');

        while ($page->get('staging')) {
            $page->call('stageNext');
        }

        $batch = ImportBatch::sole();
        $duplicate = $batch->rows()->where('action', 'duplicate')->sole();

        $page->assertSee('Import blocked — this file has 1 duplicate(s)')
            ->assertDontSee('Ready to import')
            ->call('startImport')
            ->assertNotified('Import blocked — the file has duplicates')
            ->call('showDuplicates')
            ->assertCanSeeTableRecords([$duplicate])
            ->assertCanNotSeeTableRecords($batch->rows()->where('action', 'new')->get());

        $this->assertSame(['staged', 0], [$batch->refresh()->status, Member::count()]);

        $page->callTableBulkAction('remove', [$duplicate])
            ->assertSee('Ready to import')
            ->call('startImport');

        while ($batch->refresh()->status === 'importing') {
            $page->call('importNext');
        }

        $this->assertSame(['00101959720', '00101959721'], Member::orderBy('account_no')->pluck('account_no')->all());
    }

    public function test_validation_can_be_cancelled_which_returns_to_the_mapping_screen_and_discards_the_rows_read(): void
    {
        Storage::fake('local');
        $this->actingAsRole(Permissions::CRS);

        $page = Livewire::test(ImportMembers::class)
            ->set('upload.file', $this->workbookUpload())
            ->call('analyze')
            ->call('validateMapping')
            ->assertSee('Validating 2 sheet(s)')
            ->call('stageNext');

        $batch = ImportBatch::sole();
        $this->assertGreaterThan(0, $batch->rows()->count());

        $page->call('cancelValidation')
            ->assertSet('staging', false)
            ->assertSet('mapping.0.include', true)
            ->assertSee('Confirm sheets, branches and columns')
            ->assertDontSee('Validating 2 sheet(s)');

        $this->assertSame(['analyzed', 0, 0], [$batch->refresh()->status, $batch->rows()->count(), Member::count()]);

        $page->call('stageNext');

        $this->assertSame(0, $batch->rows()->count(), 'A late poll after cancelling reads nothing.');
    }

    public function test_sheet_without_a_branch_must_be_mapped_before_validation(): void
    {
        Storage::fake('local');
        $this->actingAsRole(Permissions::ADMIN);

        $page = Livewire::test(ImportMembers::class)
            ->set('upload.file', $this->workbookUpload())
            ->call('analyze')
            ->set('mapping.0.branch_id', null)
            ->call('validateMapping')
            ->assertNotified('Mapping incomplete')
            ->assertSet('staging', false);

        $page->set('mapping.0.branch_id', Branch::where('name', 'Culasi')->value('id'))
            ->call('validateMapping')
            ->assertSet('staging', true);
    }

    public function test_crs_officer_with_a_home_branch_imports_only_that_branchs_sheet(): void
    {
        Storage::fake('local');
        $barbaza = Branch::where('name', 'Barbaza')->value('id');
        Member::factory()->create(['account_no' => '00101959720', 'branch_id' => Branch::where('name', 'Kalibo')->value('id')]);
        $this->actingAs(User::factory()->create(['is_active' => true, 'branch_id' => $barbaza])->assignRole(Permissions::CRS));

        $page = Livewire::test(ImportMembers::class)
            ->set('upload.file', $this->workbookUpload())
            ->call('analyze')
            ->assertSet('mapping.0.include', true)
            ->assertSet('mapping.1.include', false)
            ->assertSee('limited to the Barbaza branch')
            ->assertSee('Branch for Barbaza')
            ->assertDontSee('Pres Roxas')
            ->assertDontSee('Notes');

        // Another branch's sheet stays out even if the hidden mapping is forced on.
        $page->set('mapping.1.include', true)
            ->set('mapping.1.branch_id', $barbaza)
            ->call('validateMapping')
            ->assertSet('staging', true);

        $this->assertSame([true, false, false], array_column(ImportBatch::sole()->sheets, 'include'));

        while ($page->get('staging')) {
            $page->call('stageNext');
        }

        $batch = ImportBatch::sole();
        $page->call('startImport')->assertNotified('Sent to the Administrator for approval');
        $this->approveAndFinish($batch);

        $this->assertSame('completed', $batch->status);
        $this->assertDatabaseCount('members', 2);
        $this->assertDatabaseHas('members', ['account_no' => '00101959720', 'branch_id' => $barbaza]);
        $this->assertDatabaseMissing('members', ['account_no' => '00101959722']);
    }

    public function test_when_no_sheet_is_named_after_the_home_branch_the_unnamed_sheets_are_offered_with_that_branch_preselected(): void
    {
        Storage::fake('local');
        $barbaza = Branch::where('name', 'Barbaza')->value('id');
        $this->actingAs(User::factory()->create(['is_active' => true, 'branch_id' => $barbaza])->assignRole(Permissions::CRS));
        $header = ['Acct. Number', 'Account Name', 'Category', 'NEW Date of Approval', 'Savings Balance'];
        $path = $this->writeMasterlistWorkbook(tempnam(sys_get_temp_dir(), 'unnamed').'.xlsx', [
            'Sheet1' => [$header, ['00101959720', 'DELA CRUZ, JUAN A.', 'COLISAP40000', '6/3/1998', 5000]],
            'Kalibo' => [$header, ['00301959721', 'SANTOS, MARIA B.', 'COLISAP40000', '6/3/1998', 800]],
        ]);
        $upload = UploadedFile::fake()->createWithContent('masterlist.xlsx', file_get_contents($path))
            ->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        Livewire::test(ImportMembers::class)
            ->set('upload.file', $upload)
            ->call('analyze')
            ->assertSee('Branch for Sheet1')
            ->assertDontSee('Branch for Kalibo')
            ->assertSet('mapping.0.branch_id', $barbaza)
            ->assertSet('mapping.1.include', false);
    }

    public function test_crs_officer_with_a_home_branch_imports_mobile_numbers_for_that_branch_only_without_choosing_a_branch(): void
    {
        Storage::fake('local');
        $barbaza = Branch::where('name', 'Barbaza')->value('id');
        Member::factory()->create(['account_no' => '00101000001', 'branch_id' => $barbaza, 'contact_number' => null]);
        Member::factory()->create(['account_no' => '00301000002', 'branch_id' => Branch::where('name', 'Kalibo')->value('id'), 'contact_number' => null]);
        $this->actingAs(User::factory()->create(['is_active' => true, 'branch_id' => $barbaza])->assignRole(Permissions::CRS));

        $path = $this->writeMasterlistWorkbook(tempnam(sys_get_temp_dir(), 'contacts').'.xlsx', [
            'Sheet1' => [
                ['Acct. Number', 'Name', 'Number'],
                ['00101000001', 'DELA CRUZ, JUAN', '09171234567'],
                ['00301000002', 'LOPEZ, ANA', '09181234567'],
            ],
        ]);
        $upload = UploadedFile::fake()->createWithContent('contacts.xlsx', file_get_contents($path))
            ->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $page = Livewire::test(ImportMembers::class)
            ->set('upload.file', $upload)
            ->call('analyze')
            ->assertSet('mapping.0.include', true)
            ->assertSee('Mobile numbers only')
            ->call('validateMapping')
            ->assertSet('staging', true);

        while ($page->get('staging')) {
            $page->call('stageNext');
        }

        $batch = ImportBatch::sole();
        $this->assertSame([1, 1], [$batch->update_rows, $batch->invalid_rows]);
        $page->call('startImport');
        $this->approveAndFinish($batch);

        $this->assertDatabaseHas('members', ['account_no' => '00101000001', 'contact_number' => '09171234567']);
        $this->assertDatabaseHas('members', ['account_no' => '00301000002', 'contact_number' => null]);
    }

    /**
     * The Administrator approves a branch's import, then it is written chunk by chunk as the browser would.
     */
    private function approveAndFinish(ImportBatch $batch): void
    {
        $this->assertSame('awaiting_approval', $batch->refresh()->status);

        $this->actingAsRole(Permissions::ADMIN);
        $service = app(MasterlistImportService::class);
        $service->approve($batch);

        while ($service->importChunk($batch->refresh()) > 0) {
            // keep importing
        }

        $batch->refresh();
    }

    public function test_a_branch_import_adds_nothing_until_the_administrator_approves_it(): void
    {
        Storage::fake('local');
        $crs = $this->actingAsRole(Permissions::CRS);

        $page = Livewire::test(ImportMembers::class)
            ->set('upload.file', $this->workbookUpload())
            ->call('analyze')
            ->call('validateMapping');

        while ($page->get('staging')) {
            $page->call('stageNext');
        }

        $page->assertSee('Ready to send for approval')
            ->assertDontSee('Ready to import')
            ->call('startImport')
            ->assertNotified('Sent to the Administrator for approval')
            ->assertSee('Waiting for the Administrator')
            ->call('importNext');

        $batch = ImportBatch::sole();
        $this->assertSame(['awaiting_approval', 0], [$batch->status, Member::count()]);

        $this->get(ImportApprovals::getUrl())->assertForbidden();

        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(ImportApprovals::class)
            ->assertCanSeeTableRecords([$batch])
            ->assertSee($crs->name)
            ->callTableAction('approve', $batch)
            ->assertRedirect(MemberResource::getUrl('import', ['batch' => $batch->id]));

        $wizard = Livewire::withQueryParams(['batch' => $batch->id])->test(ImportMembers::class);

        while ($batch->refresh()->status === 'importing') {
            $wizard->call('importNext');
        }

        $this->assertSame(['completed', 3], [$batch->status, Member::count()]);
        Livewire::test(ImportApprovals::class)->assertCanNotSeeTableRecords([$batch]);
    }

    public function test_the_administrator_rejects_a_branch_import_with_a_reason_and_nothing_is_added(): void
    {
        Storage::fake('local');
        $this->actingAsRole(Permissions::CRS);

        $page = Livewire::test(ImportMembers::class)
            ->set('upload.file', $this->workbookUpload())
            ->call('analyze')
            ->call('validateMapping');

        while ($page->get('staging')) {
            $page->call('stageNext');
        }

        $page->call('startImport');
        $batch = ImportBatch::sole();

        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(ImportApprovals::class)
            ->callTableAction('reject', $batch, data: ['reason' => 'Wrong month — please upload the September file.'])
            ->assertNotified('Import rejected')
            ->assertCanNotSeeTableRecords([$batch]);

        $batch->refresh();
        $this->assertSame(['rejected', 'Wrong month — please upload the September file.', 0], [$batch->status, $batch->error_message, Member::count()]);

        Livewire::withQueryParams(['batch' => $batch->id])
            ->test(ImportMembers::class)
            ->assertSee('The Administrator rejected this import')
            ->assertSee('Wrong month — please upload the September file.');
    }

    public function test_steps_already_reached_can_be_opened_again_without_changing_the_import(): void
    {
        Storage::fake('local');
        $this->actingAsRole(Permissions::ADMIN);

        $page = Livewire::test(ImportMembers::class)
            ->set('upload.file', $this->workbookUpload())
            ->call('analyze')
            ->call('goToStep', 2)
            ->assertSet('viewStep', null)
            ->assertSee('Confirm sheets, branches and columns')
            ->call('validateMapping');

        while ($page->get('staging')) {
            $page->call('stageNext');
        }

        $batch = ImportBatch::sole();
        $stagedRows = $batch->rows()->count();

        $page->assertSee('Ready to import')
            ->call('goToStep', 0)
            ->assertSee('Uploaded file')
            ->assertSee('masterlist.xlsx')
            ->assertDontSee('Ready to import')
            ->call('goToStep', 1)
            ->assertSee('Sheets and branches used')
            ->assertSee('President Roxas')
            ->call('goToStep', 3)
            ->assertSet('viewStep', 1)
            ->call('goToStep', 2)
            ->assertSet('viewStep', null)
            ->assertSee('Ready to import');

        $this->assertSame(['staged', $stagedRows], [$batch->refresh()->status, $batch->rows()->count()]);
    }

    public function test_users_without_the_import_permission_cannot_open_the_import_page(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]));

        $this->get(MemberResource::getUrl('import'))->assertForbidden();
    }
}
