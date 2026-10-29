<?php

namespace App\Filament\Resources\BmpcMemberResource\Pages;

use App\Filament\Resources\BmpcMemberResource;
use App\Models\ImportBatch;
use App\Services\Masterlist\BmpcMemberImportService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The BMPC member list with its Import button. A workbook is imported one sheet per request (the page polls
 * importNextSheet()), so a large member list never runs into a request time limit. Every upload is logged in
 * Import History, where the Administrator can delete it together with the members it added.
 */
class ListBmpcMembers extends ListRecords
{
    protected static string $resource = BmpcMemberResource::class;

    protected static string $view = 'filament.resources.bmpc-member-resource.pages.list-bmpc-members';

    private const DISK = 'local';

    private const DIRECTORY = 'bmpc-member-imports';

    /**
     * The uploaded workbook being imported, relative to the local disk; null when no import is running.
     */
    public ?string $importFile = null;

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
            Actions\Action::make('import')
                ->label('Import Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(Auth::user()?->can('bmpc_members.import') ?? false)
                ->disabled(fn () => $this->importFile !== null)
                ->modalHeading('Import BMPC members')
                ->modalDescription('Upload the coop\'s member list. The system finds the account number (or CIF Key), name, branch, birthdate, contact number and savings balance, and ignores every other column. Members already here are updated; new ones are added right away.')
                ->modalSubmitActionLabel('Import')
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
                    try {
                        $sheets = app(BmpcMemberImportService::class)->sheets(Storage::disk(self::DISK)->path($data['file']));
                    } catch (Throwable $exception) {
                        report($exception);
                        Storage::disk(self::DISK)->delete($data['file']);
                        Notification::make()->danger()->title('The file could not be read')->body('Check that it is an Excel, OpenDocument or CSV file and try again.')->send();

                        return;
                    }

                    if ($sheets === []) {
                        Storage::disk(self::DISK)->delete($data['file']);
                        Notification::make()->warning()->title('No member list found')->body('No sheet has an account number (or CIF Key) column together with a name column.')->send();

                        return;
                    }

                    $this->importBatchId = app(BmpcMemberImportService::class)->startBatch($data['original_name'] ?? basename($data['file']), $sheets)->id;
                    $this->importFile = $data['file'];
                    $this->importSheets = $sheets;
                    $this->importSheetCount = count($sheets);
                    $this->importSummary = BmpcMemberImportService::emptySummary();
                }),
        ];
    }

    /**
     * Import the next sheet of the running import; called by the progress panel until every sheet is done.
     */
    public function importNextSheet(): void
    {
        if ($this->importFile === null) {
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
        $this->importSheets = [];
        $this->importSheetCount = 0;
        $this->importSummary = [];
        $this->resetTable();

        if ($errorMessage !== null) {
            return;
        }

        $notes = array_filter([
            $summary['skipped'] ? number_format($summary['skipped']).' row(s) without a usable account number or name were skipped.' : null,
            $summary['other_branch'] ? number_format($summary['other_branch']).' member(s) of other branches were left out.' : null,
        ]);

        Notification::make()
            ->success()
            ->title(number_format($summary['added']).' added, '.number_format($summary['updated']).' updated')
            ->body($notes === [] ? null : implode(' ', $notes))
            ->send();
    }
}
