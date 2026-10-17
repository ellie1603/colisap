<?php

namespace App\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\ImportBatchResource;
use App\Filament\Resources\MemberResource;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Services\Masterlist\MasterlistImportService;
use App\Services\Masterlist\WorkbookParser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Masterlist import wizard: Upload → Map sheets & columns → Validate (sheet by sheet) → Preview → Import (in chunks).
 * Each step is a short request driven by the browser, so files of any size import without timeouts or a queue worker.
 */
class ImportMembers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = MemberResource::class;

    protected static string $view = 'filament.resources.member-resource.pages.import-members';

    protected static ?string $title = 'Import Excel Masterlist';

    protected static ?string $breadcrumb = 'Import';

    private const IMPORT_CHUNK = 200;

    #[Url(as: 'batch')]
    public ?int $batchId = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $upload = [];

    /**
     * Per-sheet mapping keyed by position: include, branch_id, columns (field => column index).
     *
     * @var array<int, array{include: bool, branch_id: int|string|null, columns: array<string, int|string|null>}>
     */
    public array $mapping = [];

    public bool $staging = false;

    /**
     * The step the user chose to look at; null follows the import's own progress.
     */
    public ?int $viewStep = null;

    public static function canAccess(array $parameters = []): bool
    {
        return Auth::user()?->can('members.import') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->uploadForm->fill();

        if ($this->batch()) {
            $this->loadMapping();
        }
    }

    protected function getForms(): array
    {
        return ['uploadForm'];
    }

    public function uploadForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('file')
                    ->label('Masterlist workbook')
                    ->helperText('Excel (.xlsx), OpenDocument (.ods) or CSV. One sheet per branch — the sheet name selects the branch. No row limit. A file with only the Acct. Number, name and mobile number updates the members\' mobile numbers and nothing else.')
                    ->disk(MasterlistImportService::DISK)
                    ->directory(MasterlistImportService::DIRECTORY)
                    ->visibility('private')
                    ->storeFileNamesIn('original_name')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.oasis.opendocument.spreadsheet',
                        'text/csv',
                        'text/plain',
                        'application/vnd.ms-excel',
                    ])
                    ->maxSize(51200)
                    ->required(),
                Forms\Components\Hidden::make('original_name'),
            ])
            ->statePath('upload');
    }

    /**
     * How far this import has come: 0 Upload, 1 Map sheets, 2 Preview, 3 Import.
     */
    public function reachedStep(): int
    {
        return match (true) {
            $this->batch() === null => 0,
            $this->staging || $this->batch()->status === 'analyzed' => 1,
            in_array($this->batch()->status, ['staged', 'awaiting_approval'], true) => 2,
            default => 3,
        };
    }

    /**
     * Look at a step already reached (nothing is changed by looking); the last one follows the import again.
     */
    public function goToStep(int $step): void
    {
        $reached = $this->reachedStep();

        if ($this->staging || $step < 0 || $step > $reached) {
            return;
        }

        $this->viewStep = $step === $reached ? null : $step;
    }

    /**
     * Branch staff send their import to the Administrator; only an approver's import is written straight away.
     */
    public function canApproveImports(): bool
    {
        return Auth::user()?->can('imports.approve') ?? false;
    }

    public function batch(): ?ImportBatch
    {
        return $this->batchId ? ImportBatch::query()->visibleToCurrentUser()->find($this->batchId) : null;
    }

    private function service(): MasterlistImportService
    {
        return app(MasterlistImportService::class);
    }

    // ------------------------------------------------------------ Step 1: upload & analyze

    public function analyze(): void
    {
        $state = $this->uploadForm->getState();

        try {
            $batch = $this->service()->createBatch($state['file'], $state['original_name'] ?? basename($state['file']));
        } catch (Throwable $exception) {
            report($exception);
            Storage::disk(MasterlistImportService::DISK)->delete($state['file']);

            Notification::make()->title('Could not read the workbook')
                ->body('Make sure it is a valid Excel file. '.$exception->getMessage())
                ->danger()->send();

            return;
        }

        $this->batchId = $batch->id;
        $this->viewStep = null;
        $this->loadMapping();

        if (collect($this->mapping)->where('include', true)->isEmpty()) {
            Notification::make()->title('Check the sheet mapping')
                ->body('No sheet could be matched automatically to a branch with an Acct. Number column. Map them below.')
                ->warning()->send();
        }
    }

    /**
     * Sheets offered on the mapping screen. Staff limited to one branch only get the sheet named after their
     * branch (and mobile-number lists); other branches' sheets are neither shown nor importable. When no sheet
     * carries their branch's name, the sheets that match no branch at all are offered so the file can still be mapped.
     *
     * @return list<int>
     */
    private function mappableSheetPositions(?ImportBatch $batch): array
    {
        $sheets = $batch?->sheets ?? [];
        $ownBranch = Branch::restrictedId();

        if ($ownBranch === null) {
            return array_keys($sheets);
        }

        $named = array_map(fn (array $sheet) => Branch::resolve($sheet['name'])?->id, $sheets);
        $hasOwnSheet = in_array($ownBranch, $named, true);

        return array_keys(array_filter($sheets, fn (array $sheet, int $position) => $named[$position] === $ownBranch
            || WorkbookParser::isContactList($sheet['columns'] ?? [])
            || (! $hasOwnSheet && $named[$position] === null), ARRAY_FILTER_USE_BOTH));
    }

    private function loadMapping(): void
    {
        $batch = $this->batch();
        $ownBranch = Branch::restrictedId();
        $mappable = $this->mappableSheetPositions($batch);

        $this->mapping = collect($batch?->sheets ?? [])->map(function (array $sheet, int $position) use ($ownBranch, $mappable) {
            $isMappable = in_array($position, $mappable, true);

            return [
                'include' => $isMappable && ($sheet['include'] ?? false),
                'branch_id' => $isMappable ? $ownBranch ?? $sheet['branch_id'] ?? null : null,
                'columns' => collect(WorkbookParser::FIELDS)->mapWithKeys(fn ($label, $field) => [$field => $sheet['columns'][$field] ?? null])->all(),
            ];
        })->all();
    }

    // ------------------------------------------------------------ Step 2: mapping → validation

    public function validateMapping(): void
    {
        $batch = $this->batch();
        $branches = Branch::pluck('name', 'id');
        $ownBranch = Branch::restrictedId();
        $mappable = $this->mappableSheetPositions($batch);
        $sheets = $batch->sheets;
        $problems = [];

        foreach ($sheets as $position => $sheet) {
            $map = $this->mapping[$position] ?? null;

            if (! $map) {
                continue;
            }

            $columns = array_filter($map['columns'], fn ($column) => $column !== null && $column !== '');
            $include = (bool) $map['include'] && in_array($position, $mappable, true);
            $isContactList = WorkbookParser::isContactList($columns);

            if ($include && ! $sheet['header_row']) {
                $problems[] = "{$sheet['name']}: no header row was found";
            } elseif ($include && ! isset($columns['account_no'])) {
                $problems[] = "{$sheet['name']}: map the Acct. Number column";
            } elseif ($isContactList) {
                // A mobile-number list matches members by Acct. Number alone: it needs no name and no branch.
                $map['branch_id'] = $ownBranch;
            } elseif ($include && ! isset($columns['full_name']) && ! (isset($columns['last_name']) && isset($columns['first_name']))) {
                $problems[] = "{$sheet['name']}: map the Account Name column";
            } elseif ($include && ! $map['branch_id']) {
                $problems[] = "{$sheet['name']}: choose a branch";
            } elseif ($include && $ownBranch !== null && (int) $map['branch_id'] !== $ownBranch) {
                $problems[] = "{$sheet['name']}: you can only import members of the {$branches[$ownBranch]} branch";
            }

            $sheets[$position]['include'] = $include;
            $sheets[$position]['branch_id'] = $map['branch_id'] ? (int) $map['branch_id'] : null;
            $sheets[$position]['branch_name'] = $map['branch_id'] && ! $isContactList ? $branches[(int) $map['branch_id']] ?? null : null;
            $sheets[$position]['columns'] = array_map('intval', $columns);
        }

        if ($problems !== []) {
            Notification::make()->title('Mapping incomplete')->body(implode('<br>', array_map('e', $problems)))->danger()->persistent()->send();

            return;
        }

        $batch->update(['sheets' => $sheets]);
        $this->viewStep = null;
        $this->service()->resetStaging($batch);

        if ($this->service()->includedSheetPositions($batch->refresh()) === []) {
            Notification::make()->title('Select at least one sheet to import')->warning()->send();

            return;
        }

        $this->staging = true;
    }

    /**
     * Polled while validating: stage the next sheet, then finalize.
     */
    public function stageNext(): void
    {
        $batch = $this->batch();

        if (! $this->staging || ! $batch) {
            return;
        }

        foreach ($this->service()->includedSheetPositions($batch) as $position) {
            if (! ($batch->sheets[$position]['staged'] ?? false)) {
                try {
                    $this->service()->stageSheet($batch, $position);
                } catch (Throwable $exception) {
                    report($exception);
                    $this->staging = false;
                    $batch->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);

                    Notification::make()->title('Validation failed')->body($exception->getMessage())->danger()->send();
                }

                return;
            }
        }

        $this->service()->finalize($batch);
        $this->staging = false;
        $this->resetTable();
    }

    /**
     * Stop checking the sheets and return to the mapping screen; the rows read so far are discarded.
     * The sheet being read when Cancel is clicked finishes first, so it can take a moment on a large sheet.
     */
    public function cancelValidation(): void
    {
        $this->staging = false;

        if ($batch = $this->batch()) {
            $this->service()->resetStaging($batch);
        }

        $this->loadMapping();
    }

    public function backToMapping(): void
    {
        $this->viewStep = null;
        $this->service()->resetStaging($this->batch());
        $this->loadMapping();
    }

    // ------------------------------------------------------------ Step 3 & 4: preview → import

    public function startImport(): void
    {
        $batch = $this->batch();

        if ($problem = $this->service()->duplicateProblem($batch)) {
            Notification::make()->title('Import blocked — the file has duplicates')->body($problem)->danger()->persistent()->send();

            return;
        }

        if ($batch->importableCount() === 0) {
            Notification::make()->title('Nothing to import')->body('There are no new or updated members in this file.')->warning()->send();

            return;
        }

        $this->viewStep = null;

        if (! $this->canApproveImports()) {
            $this->service()->submitForApproval($batch);

            Notification::make()->title('Sent to the Administrator for approval')
                ->body('Nothing has been added to the system yet. The members are written once the Administrator approves this import.')
                ->success()->send();

            return;
        }

        $batch->update(['status' => 'importing']);
    }

    /**
     * The Administrator approves a branch's import from the preview; the import then runs on this page.
     */
    public function approveImport(): void
    {
        abort_unless($this->canApproveImports(), 403);

        try {
            $this->service()->approve($this->batch());
        } catch (\RuntimeException $exception) {
            Notification::make()->title('This import cannot be approved')->body($exception->getMessage())->danger()->persistent()->send();

            return;
        }

        $this->viewStep = null;
    }

    /**
     * Narrow the preview to the rows that block the import.
     */
    public function showDuplicates(): void
    {
        $this->tableFilters['action']['value'] = 'duplicate';
        $this->resetPage();
    }

    /**
     * Polled while importing: write the next chunk of rows.
     */
    public function importNext(): void
    {
        $batch = $this->batch();

        if (! $batch || $batch->status !== 'importing') {
            return;
        }

        $processed = $this->service()->importChunk($batch, self::IMPORT_CHUNK);

        if ($processed === 0) {
            $batch->refresh();

            Notification::make()->title('Masterlist imported')
                ->body("{$batch->created_count} added, {$batch->updated_count} updated, {$batch->unchanged_count} unchanged. Statuses were recalculated.")
                ->success()->send();

            $this->resetTable();
        }
    }

    public function cancel(): void
    {
        if ($batch = $this->batch()) {
            $this->service()->cancel($batch);
        }

        $this->redirect(ImportBatchResource::getUrl('index'));
    }

    /**
     * Drop a file that failed the check and go straight back to the upload step.
     */
    public function uploadCorrectedFile(): void
    {
        if ($batch = $this->batch()) {
            $this->service()->cancel($batch);
        }

        $this->startOver();
    }

    public function startOver(): void
    {
        $this->batchId = null;
        $this->viewStep = null;
        $this->mapping = [];
        $this->staging = false;
        $this->uploadForm->fill();
    }

    public function downloadIssues(): StreamedResponse
    {
        return $this->service()->errorReport($this->batch());
    }

    // ------------------------------------------------------------ Staged rows table

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => ImportRow::query()->where('import_batch_id', $this->batchId ?? 0)->where('action', '<>', 'reference'))
            ->heading('Rows in this file')
            ->columns([
                Tables\Columns\TextColumn::make('sheet')->searchable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('row_number')->label('Row'),
                Tables\Columns\TextColumn::make('account_no')->label('Acct. Number')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('account_name')->label('Account Name')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('action')->label('Classification')->badge()
                    ->formatStateUsing(fn (string $state) => ImportRow::ACTIONS[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => ImportRow::actionColor($state)),
                Tables\Columns\TextColumn::make('result')->badge()->color('gray')->placeholder('—')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('issues')
                    ->state(fn (ImportRow $record) => implode(' · ', [...($record->errors ?? []), ...($record->warnings ?? [])]))
                    ->color(fn (ImportRow $record) => ($record->errors ?? []) !== [] ? 'danger' : 'warning')
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')->label('Classification')->options(ImportRow::ACTIONS),
                Tables\Filters\SelectFilter::make('sheet')->options(fn () => collect($this->batch()?->sheets ?? [])->pluck('name', 'name')->all()),
                Tables\Filters\Filter::make('has_warnings')->label('With warnings')
                    ->query(fn ($query) => $query->whereNotNull('warnings')->where('warnings', '<>', '[]')),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('remove')
                    ->label('Delete selected')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete the selected rows from this import?')
                    ->modalDescription('The rows are removed from this import only and will not be written to the system. Nothing changes in the Excel file or in members already saved.')
                    ->modalSubmitActionLabel('Delete rows')
                    ->visible(fn () => $this->batch()?->status === 'staged')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $this->removeStagedRows($records)),
            ])
            ->defaultSort('id')
            ->paginated([10, 25, 50, 100]);
    }

    /**
     * Drop staged rows the user does not want imported (rows with issues, wrong entries) and refresh the totals.
     *
     * @param  Collection<int, ImportRow>  $rows
     */
    public function removeStagedRows(Collection $rows): void
    {
        $batch = $this->batch();

        if (! $batch || $batch->status !== 'staged') {
            Notification::make()->title('Rows can only be deleted before the import starts')->warning()->send();

            return;
        }

        $removed = ImportRow::query()
            ->where('import_batch_id', $batch->id)
            ->where('action', '<>', 'reference')
            ->whereKey($rows->modelKeys())
            ->delete();

        $this->service()->refreshCounts($batch);
        $this->resetTable();

        Notification::make()->title($removed === 1 ? '1 row deleted from this import' : number_format($removed).' rows deleted from this import')->success()->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'batch' => $batch = $this->batch(),
            'canApprove' => $this->canApproveImports(),
            'mappableSheets' => $this->mappableSheetPositions($batch),
            'branches' => Branch::options(),
            'ownBranchName' => Branch::whereKey(Branch::restrictedId())->value('name'),
            'fields' => WorkbookParser::FIELDS,
        ];
    }
}
