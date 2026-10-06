<?php

namespace Tests\Feature\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\MemberResource;
use App\Filament\Resources\MemberResource\Pages\ImportMembers;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\Member;
use App\Models\User;
use App\Services\Access\Permissions;
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

    public function test_crs_imports_a_masterlist_through_every_step(): void
    {
        Storage::fake('local');
        $this->actingAsRole(Permissions::CRS);

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
        $this->actingAsRole(Permissions::CRS);

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
        $roxas = Branch::where('name', 'President Roxas')->value('id');
        Member::factory()->create(['account_no' => '00101959720', 'branch_id' => Branch::where('name', 'Kalibo')->value('id')]);
        $this->actingAs(User::factory()->create(['is_active' => true, 'branch_id' => $barbaza])->assignRole(Permissions::CRS));

        $page = Livewire::test(ImportMembers::class)
            ->set('upload.file', $this->workbookUpload())
            ->call('analyze')
            ->assertSet('mapping.0.include', true)
            ->assertSet('mapping.1.include', false)
            ->assertSee('limited to the Barbaza branch');

        $page->set('mapping.1.include', true)
            ->set('mapping.1.branch_id', $roxas)
            ->call('validateMapping')
            ->assertNotified('Mapping incomplete')
            ->assertSet('staging', false);

        $page->set('mapping.1.include', false)->call('validateMapping')->assertSet('staging', true);

        while ($page->get('staging')) {
            $page->call('stageNext');
        }

        $batch = ImportBatch::sole();
        $page->call('startImport');

        while ($batch->refresh()->status === 'importing') {
            $page->call('importNext');
        }

        $this->assertSame('completed', $batch->status);
        $this->assertDatabaseCount('members', 2);
        $this->assertDatabaseHas('members', ['account_no' => '00101959720', 'branch_id' => $barbaza]);
        $this->assertDatabaseMissing('members', ['account_no' => '00101959722']);
    }

    public function test_users_without_the_import_permission_cannot_open_the_import_page(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]));

        $this->get(MemberResource::getUrl('import'))->assertForbidden();
    }
}
