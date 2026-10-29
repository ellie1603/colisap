<?php

namespace Tests\Feature\Filament\Resources\ImportBatchResource;

use App\Filament\Resources\ImportBatchResource;
use App\Filament\Resources\ImportBatchResource\Pages\ListImportBatches;
use App\Filament\Resources\ImportBatchResource\Pages\ViewImportBatch;
use App\Models\BmpcMember;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Member;
use App\Models\User;
use App\Services\Access\Permissions;
use App\Services\Masterlist\BmpcMemberImportService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class ImportHistoryTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    /**
     * A completed import that added two members.
     *
     * @return array{0: ImportBatch, 1: list<Member>}
     */
    private function completedImport(): array
    {
        Storage::fake('local');
        Storage::disk('local')->put('masterlist-imports/masterlist.xlsx', 'workbook');

        $batch = ImportBatch::create([
            'file_name' => 'COLISAP MASTERLIST.xlsx',
            'file_path' => 'masterlist-imports/masterlist.xlsx',
            'as_of_date' => now(),
            'status' => 'completed',
            'created_count' => 2,
        ]);

        $members = Member::factory()->count(2)->create(['import_batch_id' => $batch->id])->all();

        foreach ($members as $index => $member) {
            ImportRow::create([
                'import_batch_id' => $batch->id, 'sheet' => 'Barbaza', 'row_number' => $index + 5, 'account_no' => $member->account_no,
                'action' => 'new', 'status' => 'imported', 'result' => 'created', 'member_id' => $member->id,
            ]);
        }

        return [$batch, $members];
    }

    public function test_administrator_deletes_an_import_and_every_member_it_added_from_import_history(): void
    {
        [$batch, $members] = $this->completedImport();
        $keep = Member::factory()->create();
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(ListImportBatches::class)
            ->assertTableActionVisible('deleteImportedData', $batch)
            ->mountTableAction('deleteImportedData', $batch)
            ->assertSee('permanently deletes 2 member(s)')
            ->callMountedTableAction()
            ->assertNotified('Imported data deleted')
            ->assertCanNotSeeTableRecords([$batch]);

        $this->assertSame([$keep->id], Member::withTrashed()->pluck('id')->all());
        $this->assertSame([0, 0], [ImportBatch::count(), ImportRow::count()]);
        Storage::disk('local')->assertMissing('masterlist-imports/masterlist.xlsx');
    }

    public function test_the_import_page_has_the_same_delete_button(): void
    {
        [$batch] = $this->completedImport();
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(ViewImportBatch::class, ['record' => $batch->getRouteKey()])
            ->callAction('deleteImportedData')
            ->assertRedirect();

        $this->assertSame([0, 0], [ImportBatch::count(), Member::count()]);
    }

    public function test_the_import_page_shows_its_details_in_a_table_without_masterlist_figures_for_bmpc_uploads(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $masterlist = ImportBatch::create(['file_name' => 'masterlist.xlsx', 'as_of_date' => now(), 'status' => 'completed', 'total_rows' => 36605]);
        $bmpc = ImportBatch::create(['type' => 'bmpc_members', 'file_name' => 'members.csv', 'as_of_date' => now(), 'status' => 'completed', 'invalid_rows' => 3]);

        Livewire::test(ViewImportBatch::class, ['record' => $masterlist->getRouteKey()])
            ->assertSeeHtml('colisap-details-table')
            ->assertSeeInOrder(['Total rows', '36,605', 'Invalid'])
            ->assertSee(['Balances as of', 'Duplicates', 'With warnings']);

        Livewire::test(ViewImportBatch::class, ['record' => $bmpc->getRouteKey()])
            ->assertSee(['BMPC members', 'Skipped'])
            ->assertDontSee(['Balances as of', 'Duplicates', 'With warnings']);
    }

    public function test_crs_officer_with_a_home_branch_sees_only_imports_of_that_branch(): void
    {
        $barbaza = Branch::where('name', 'Barbaza')->value('id');
        $row = ['sheet' => 'Sheet', 'row_number' => 5, 'account_no' => '00101000001', 'action' => 'new', 'status' => 'imported'];

        $ownBranchImport = ImportBatch::create(['file_name' => 'barbaza.xlsx', 'as_of_date' => now(), 'status' => 'completed']);
        ImportRow::create([...$row, 'import_batch_id' => $ownBranchImport->id, 'branch_id' => $barbaza]);

        $allBranchesImport = ImportBatch::create(['file_name' => 'all-branches.xlsx', 'as_of_date' => now(), 'status' => 'completed']);
        ImportRow::create([...$row, 'import_batch_id' => $allBranchesImport->id, 'branch_id' => $barbaza]);
        ImportRow::create([...$row, 'import_batch_id' => $allBranchesImport->id, 'branch_id' => Branch::where('name', 'Kalibo')->value('id')]);

        $someoneElsesUpload = ImportBatch::create(['file_name' => 'not-mapped-yet.xlsx', 'as_of_date' => now(), 'status' => 'analyzed']);

        $this->actingAs(User::factory()->create(['is_active' => true, 'branch_id' => $barbaza])->assignRole(Permissions::CRS));

        Livewire::test(ListImportBatches::class)
            ->assertCanSeeTableRecords([$ownBranchImport])
            ->assertCanNotSeeTableRecords([$allBranchesImport, $someoneElsesUpload]);

        $this->get(ImportBatchResource::getUrl('view', ['record' => $allBranchesImport]))->assertNotFound();
    }

    public function test_administrator_deletes_a_bmpc_member_import_and_only_the_members_it_added(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $import = app(BmpcMemberImportService::class);
        $earlier = BmpcMember::factory()->create(['account_no' => '00101959720', 'contact_number' => null]);
        $batch = $import->startBatch('BMPC MEMBERS.xlsx', [['name' => 'Members']]);
        $added = BmpcMember::factory()->count(2)->create(['import_batch_id' => $batch->id]);
        $import->finishBatch($batch, ['added' => 2, 'updated' => 1, 'skipped' => 0, 'other_branch' => 0, 'sheets' => 1]);

        Livewire::test(ListImportBatches::class)
            ->assertCanSeeTableRecords([$batch])
            ->assertSee('BMPC members')
            ->assertTableActionHidden('resume', $batch)
            ->mountTableAction('deleteImportedData', $batch)
            ->assertSee('permanently deletes 2 BMPC member(s)')
            ->callMountedTableAction()
            ->assertNotified('Imported data deleted');

        $this->assertSame([$earlier->id], BmpcMember::pluck('id')->all(), 'A member the upload only updated stays.');
        $this->assertSame(0, ImportBatch::count());
        $this->assertSame(0, BmpcMember::whereIn('id', $added->modelKeys())->count());
    }

    public function test_crs_officers_cannot_delete_imported_data(): void
    {
        [$batch] = $this->completedImport();
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListImportBatches::class)->assertTableActionHidden('deleteImportedData', $batch);
        Livewire::test(ViewImportBatch::class, ['record' => $batch->getRouteKey()])->assertActionHidden('deleteImportedData');

        $this->assertSame(2, Member::count());
    }
}
