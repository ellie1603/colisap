<?php

namespace App\Filament\Resources\BmpcMemberResource\Pages;

use App\Filament\Resources\BmpcMemberResource;
use App\Filament\Resources\BmpcMemberResource\Widgets\BmpcMemberStats;
use App\Models\ImportBatch;
use App\Services\Masterlist\BmpcMemberImportService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The BMPC member list with its Import button. After the upload, staff check which header of the file each detail
 * is read from (pre-filled with the headers found automatically), then the workbook is imported one sheet per
 * request (the page polls importNextSheet()), so a large member list never runs into a request time limit. Every
 * upload is logged in Import History, where the Administrator can delete it together with the members it added.
 */
class ListBmpcMembers extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = BmpcMemberResource::class;

    protected static string $view = 'filament.resources.bmpc-member-resource.pages.list-bmpc-members';

    private const DISK = 'local';

    private const DIRECTORY = 'bmpc-member-imports';

    /**
     * The uploaded workbook being mapped or imported, relative to the local disk; null when no import is running.
     */
    public ?string $importFile = null;

    public ?string $importFileName = null;

    /**
     * The workbook's sheets while staff choose the columns; empty once the import starts.
     *
     * @var list<array{index: int, name: string, header_row: int, headers: array<int, string>, columns: array<string, int>, branch_id: ?int, branch_column: ?int, contact_columns: list<int>, last_transaction_column: ?int}>
     */
    public array $mappingSheets = [];

    /**
     * Sheets still to import.
     *
     * @var list<array{index: int, name: string, header_row: int, columns: array<string, int>, branch_id: ?int}>
     */
    public array $importSheets = [];

    public int $importSheetCount = 0;

    /**
     * The Import History entry of the running import.
     */
    public ?int $importBatchId = null;

    /**
     * @var array{added: int, updated: int, skipped: int, other_branch: int, sheets: int}
     */
    public array $importSummary = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add member')
                ->icon('heroicon-o-plus')
                ->modalHeading('Add BMPC member')
                ->modalDescription('Add one member of the coop by hand. Leave a field empty when it is not known; the next import of the same CIF Key fills it in.')
                ->modalSubmitActionLabel('Add member')
                ->modalWidth(MaxWidth::FiveExtraLarge)
                ->createAnother(false)
                ->mutateFormDataUsing(fn (array $data): array => BmpcMemberResource::withCreationDefaults($data))
                ->successNotificationTitle('Member added'),
            Actions\Action::make('import')
                ->label('Import Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(Auth::user()?->can('bmpc_members.import') ?? false)
                ->disabled(fn () => $this->importFile !== null)
                ->modalHeading('Import BMPC members')
                ->modalDescription('Upload the coop\'s member list. Next you choose which column of the file each detail comes from — savings number, CIF Key, Account Name, branch, birthdate, contact number, savings balance, last transaction date, segmentation, category, status and remarks are matched for you. A detail the file does not have is left empty. Members already here (same CIF Key) are updated; new ones are added.')
                ->modalSubmitActionLabel('Next: match columns')
                ->form([
                    Forms\Components\FileUpload::make('file')
                        ->label('Member list workbook')
                        ->helperText('Excel (.xlsx), OpenDocument (.ods) or CSV. Every sheet is read.')
                        ->disk(self::DISK)
                        ->directory(self::DIRECTORY)
                        ->storeFileNamesIn('original_name')
                        ->visibility('private')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.oasis.opendocument.spreadsheet',
                            'text/csv',
                            'text/plain',
                            'application/vnd.ms-excel',
                        ])
                        ->maxSize(51200)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $service = app(BmpcMemberImportService::class);
                    $this->deleteAbandonedUploads($data['file']);

                    try {
                        $sheets = $service->mappableSheets(Storage::disk(self::DISK)->path($data['file']));
                    } catch (Throwable $exception) {
                        report($exception);
                        Storage::disk(self::DISK)->delete($data['file']);
                        Notification::make()->danger()->title('The file could not be read')->body('Check that it is an Excel (.xlsx), OpenDocument or CSV file and try again.')->send();

                        return;
                    }

                    if ($sheets === []) {
                        Storage::disk(self::DISK)->delete($data['file']);
                        Notification::make()->warning()->title('No member list found')->body('No sheet has a header row with a CIF Key or Account Name column.')->send();

                        return;
                    }

                    $this->importFile = $data['file'];
                    $this->importFileName = $data['original_name'] ?? basename($data['file']);
                    $this->mappingSheets = $sheets;
                    $this->replaceMountedAction('mapColumns');
                }),
        ];
    }

    /**
     * The second window of the Import button: which header of the file each detail is read from, pre-filled with
     * the headers found automatically. Closing it without importing deletes the upload (see unmountAction()).
     */
    public function mapColumnsAction(): Actions\Action
    {
        return Actions\Action::make('mapColumns')
            ->modalHeading('Match the columns')
            ->modalDescription(fn () => "{$this->importFileName} · ".count($this->mappingSheets).' sheet(s). Choose the column of the file each detail comes from — the ones found automatically are already picked. Leave a detail on "not in file" to keep it empty.')
            ->modalWidth(MaxWidth::FiveExtraLarge)
            ->modalSubmitActionLabel('Import')
            ->closeModalByClickingAway(false)
            ->fillForm(fn () => app(BmpcMemberImportService::class)->suggestedMapping($this->mappingSheets))
            ->form(function (): array {
                $service = app(BmpcMemberImportService::class);
                $headers = $service->headerOptions($this->mappingSheets);
                $options = array_combine($headers, $headers);
                // The savings system's export has the savings number in three columns (Br, Grp, AcctNo.).
                $savingsNoOptions = $service->canBuildSavingsNumber($this->mappingSheets) ? [BmpcMemberImportService::BUILT_SAVINGS_NO => BmpcMemberImportService::BUILT_SAVINGS_NO, ...$options] : $options;

                return [
                    Forms\Components\Grid::make(['default' => 1, 'sm' => 2, 'lg' => 4])->schema(
                        collect(BmpcMemberImportService::MAPPABLE_FIELDS)
                            ->map(fn (string $label, string $field) => Forms\Components\Select::make($field)
                                ->label($label)
                                ->options($field === 'savings_no' ? $savingsNoOptions : $options)
                                ->placeholder('— not in file —')
                                ->required(in_array($field, ['cif_key', 'account_name'], true)))
                            ->values()
                            ->all(),
                    ),
                ];
            })
            ->action(fn (array $data, Actions\Action $action) => $this->startImport($data, $action));
    }

    /**
     * Start reading the workbook with the columns chosen on the mapping step.
     *
     * @param  array<string, ?string>  $mapping  field => header text
     */
    private function startImport(array $mapping, Actions\Action $action): void
    {
        if ($this->importFile === null || $this->mappingSheets === []) {
            return;
        }

        $service = app(BmpcMemberImportService::class);
        $sheets = $service->applyMapping($this->mappingSheets, $mapping);

        if ($sheets === []) {
            Notification::make()->danger()->title('No sheet has those columns')->body('Choose headers that the member list sheets have.')->send();
            $action->halt();
        }

        $this->importBatchId = $service->startBatch($this->importFileName ?? basename($this->importFile), $sheets)->id;
        $this->importSheets = $sheets;
        $this->importSheetCount = count($sheets);
        $this->importSummary = BmpcMemberImportService::emptySummary();
        $this->mappingSheets = [];
    }

    /**
     * Uploads left behind when the page was closed or reloaded with the Match the columns window open.
     */
    private function deleteAbandonedUploads(string $currentFile): void
    {
        $disk = Storage::disk(self::DISK);

        foreach ($disk->files(self::DIRECTORY) as $file) {
            if ($file !== $currentFile && $disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }

    /**
     * Closing the Match the columns window (Cancel, ✕ or Esc) without importing deletes the upload.
     */
    public function unmountAction(bool $shouldCancelParentActions = true, bool $shouldCloseModal = true): void
    {
        $isMatchingColumns = $this->getMountedAction()?->getName() === 'mapColumns';

        parent::unmountAction($shouldCancelParentActions, $shouldCloseModal);

        if ($isMatchingColumns) {
            $this->cancelImport();
        }
    }

    /**
     * Leave the mapping step without importing; the uploaded file is deleted.
     */
    private function cancelImport(): void
    {
        if ($this->importFile === null || $this->importBatchId !== null) {
            return;
        }

        Storage::disk(self::DISK)->delete($this->importFile);
        $this->importFile = null;
        $this->importFileName = null;
        $this->mappingSheets = [];
    }

    protected function getHeaderWidgets(): array
    {
        return [BmpcMemberStats::class];
    }

    /**
     * Import the next sheet of the running import; called by the progress panel until every sheet is done.
     */
    public function importNextSheet(): void
    {
        if ($this->importFile === null || $this->importBatchId === null) {
            return;
        }

        $sheet = array_shift($this->importSheets);

        if ($sheet !== null) {
            // One sheet of a large member list can take a minute to read.
            set_time_limit(600);

            try {
                app(BmpcMemberImportService::class)->importSheet(Storage::disk(self::DISK)->path($this->importFile), $sheet, $this->importSummary, $this->importBatchId);
            } catch (Throwable $exception) {
                report($exception);
                $this->finishImport("Sheet \"{$sheet['name']}\" could not be read.");
                Notification::make()->danger()->title('The import stopped')->body("Sheet \"{$sheet['name']}\" could not be read. Members from the sheets before it were saved.")->send();

                return;
            }
        }

        if ($this->importSheets === []) {
            $this->finishImport();
        }
    }

    private function finishImport(?string $errorMessage = null): void
    {
        $summary = $this->importSummary;
        Storage::disk(self::DISK)->delete($this->importFile);
        Auth::user()?->recordActivity('bmpc_members_imported', 'BMPC Members', $summary);

        if ($batch = ImportBatch::find($this->importBatchId)) {
            app(BmpcMemberImportService::class)->finishBatch($batch, $summary, $errorMessage);
        }

        $this->importBatchId = null;
        $this->importFile = null;
        $this->importFileName = null;
        $this->importSheets = [];
        $this->importSheetCount = 0;
        $this->importSummary = [];
        $this->resetTable();

        if ($errorMessage !== null) {
            return;
        }

        $notes = array_filter([
            $summary['skipped'] ? number_format($summary['skipped']).' row(s) without a usable CIF Key or Account Name were skipped.' : null,
            $summary['other_branch'] ? number_format($summary['other_branch']).' member(s) of other branches were left out.' : null,
        ]);

        Notification::make()
            ->success()
            ->title(number_format($summary['added']).' added, '.number_format($summary['updated']).' updated')
            ->body($notes === [] ? null : implode(' ', $notes))
            ->send();
    }
}
