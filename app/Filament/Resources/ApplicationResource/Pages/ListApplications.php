<?php

namespace App\Filament\Resources\ApplicationResource\Pages;

use App\Filament\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\ImportBatch;
use App\Services\Masterlist\SavingsImportService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Member transactions, with the savings import: a branch's savings list updates the balances and last transaction
 * dates of members already in the system, one sheet per request (the page polls importNextSavingsSheet()).
 */
class ListApplications extends ListRecords
{
    protected static string $resource = ApplicationResource::class;

    protected static string $view = 'filament.resources.application-resource.pages.list-applications';

    private const DISK = 'local';

    private const DIRECTORY = 'savings-imports';

    /**
     * The uploaded savings list being imported, relative to the local disk; null when no import is running.
     */
    public ?string $savingsFile = null;

    /**
     * Sheets still to import.
     *
     * @var list<array{index: int, name: string, header_row: int, account_column: int, balance_column: int, last_transaction_column: ?int}>
     */
    public array $savingsSheets = [];

    public int $savingsSheetCount = 0;

    public ?int $savingsBatchId = null;

    /**
     * @var array{updated: int, unchanged: int, not_found: int, other_branch: int, skipped: int, sheets: int}
     */
    public array $savingsSummary = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importSavings')
                ->label('Import savings')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn () => Auth::user()?->can('savings.manage') ?? false)
                ->disabled(fn () => $this->savingsFile !== null)
                ->modalHeading('Import savings')
                ->modalDescription('Upload a savings list to update members already in the system. The system reads the Acct. Number (or CIF Key), the savings balance and the last transaction date: each change is recorded as a savings transaction, and members with no transaction for 3 months are marked dormant in transaction, and those below the dormant-in-balance amount dormant in balance.')
                ->modalSubmitActionLabel('Import')
                ->form([
                    Forms\Components\FileUpload::make('file')
                        ->label('Savings list')
                        ->helperText('Excel (.xlsx), OpenDocument (.ods) or CSV, one row per member. Accounts that are not members are skipped.')
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
                ->action(fn (array $data) => $this->startSavingsImport($data)),
            Actions\CreateAction::make()
                ->label('New transaction')
                ->modalHeading('New transaction')
                ->modalDescription('Register a new member, upgrade a member, or re-apply a returning member.')
                ->modalSubmitActionLabel('Save transaction')
                ->modalWidth(MaxWidth::FiveExtraLarge)
                ->createAnother(false)
                ->mutateFormDataUsing(fn (array $data): array => ApplicationResource::withCreationDefaults($data))
                ->successNotification(fn (Notification $notification, Application $record) => $notification
                    ->title('Transaction saved')
                    ->body(match ($record->type) {
                        'upgrade' => 'It is pending. The upgrade waiting period starts once the Administrator approves it.',
                        'reapplication' => 'It is pending and has been sent to the Branch Manager. The member is reinstated once they approve it.',
                        default => 'It is pending. The member is added to Members once the Administrator approves it.',
                    })),
        ];
    }

    /**
     * @param  array{file: string, original_name?: ?string}  $data
     */
    private function startSavingsImport(array $data): void
    {
        $service = app(SavingsImportService::class);

        try {
            $sheets = $service->sheets(Storage::disk(self::DISK)->path($data['file']));
        } catch (Throwable $exception) {
            report($exception);
            Storage::disk(self::DISK)->delete($data['file']);
            Notification::make()->danger()->title('The file could not be read')->body('Check that it is an Excel, OpenDocument or CSV file and try again.')->send();

            return;
        }

        if ($sheets === []) {
            Storage::disk(self::DISK)->delete($data['file']);
            Notification::make()->warning()->title('No savings list found')->body('No sheet has an Acct. Number (or CIF Key) column together with a savings balance column.')->send();

            return;
        }

        $this->savingsBatchId = $service->startBatch($data['original_name'] ?? basename($data['file']), $sheets)->id;
        $this->savingsFile = $data['file'];
        $this->savingsSheets = $sheets;
        $this->savingsSheetCount = count($sheets);
        $this->savingsSummary = SavingsImportService::emptySummary();
    }

    /**
     * Import the next sheet of the running savings import; called by the progress panel until every sheet is done.
     */
    public function importNextSavingsSheet(): void
    {
        $batch = ImportBatch::find($this->savingsBatchId);

        if ($this->savingsFile === null || $batch === null) {
            return;
        }

        $sheet = array_shift($this->savingsSheets);

        if ($sheet !== null) {
            set_time_limit(600);

            try {
                app(SavingsImportService::class)->importSheet(Storage::disk(self::DISK)->path($this->savingsFile), $sheet, $this->savingsSummary, $batch);
            } catch (Throwable $exception) {
                report($exception);
                $this->finishSavingsImport($batch, "Sheet \"{$sheet['name']}\" could not be read.");
                Notification::make()->danger()->title('The import stopped')->body("Sheet \"{$sheet['name']}\" could not be read. Savings from the sheets before it were saved.")->send();

                return;
            }
        }

        if ($this->savingsSheets === []) {
            $this->finishSavingsImport($batch);
        }
    }

    private function finishSavingsImport(ImportBatch $batch, ?string $errorMessage = null): void
    {
        $summary = $this->savingsSummary;
        Storage::disk(self::DISK)->delete($this->savingsFile);
        app(SavingsImportService::class)->finishBatch($batch, $summary, $errorMessage);
        Auth::user()?->recordActivity('savings_imported', 'Member Transactions', $summary);

        $this->savingsBatchId = null;
        $this->savingsFile = null;
        $this->savingsSheets = [];
        $this->savingsSheetCount = 0;
        $this->savingsSummary = [];

        if ($errorMessage !== null) {
            return;
        }

        $notes = array_filter([
            $summary['not_found'] ? number_format($summary['not_found']).' account(s) are not members and were skipped.' : null,
            $summary['other_branch'] ? number_format($summary['other_branch']).' member(s) of other branches were left out.' : null,
            $summary['skipped'] ? number_format($summary['skipped']).' row(s) without an Acct. Number or a readable balance were skipped.' : null,
        ]);

        Notification::make()
            ->success()
            ->title(number_format($summary['updated']).' member(s) updated, '.number_format($summary['unchanged']).' unchanged')
            ->body($notes === [] ? null : implode(' ', $notes))
            ->send();
    }
}
