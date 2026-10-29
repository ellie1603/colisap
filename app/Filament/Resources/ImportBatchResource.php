<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ImportBatchResource\Pages;
use App\Filament\Resources\ImportBatchResource\RelationManagers;
use App\Models\ImportBatch;
use App\Services\Masterlist\BmpcMemberImportService;
use App\Services\Masterlist\MasterlistImportService;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ImportBatchResource extends Resource
{
    protected static ?string $model = ImportBatch::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box-arrow-down';

    protected static ?string $navigationGroup = 'Data & Reports';

    protected static ?string $navigationLabel = 'Import History';

    protected static ?string $modelLabel = 'import';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->schema([
                Infolists\Components\ViewEntry::make('details')
                    ->hiddenLabel()
                    ->view('filament.details-table')
                    ->state(fn (ImportBatch $record) => static::detailRows($record)),
            ]),
        ]);
    }

    /**
     * The import's details as table rows of one or two label / value pairs (see filament/details-table.blade.php).
     * Masterlist-only figures are left out of BMPC member uploads.
     *
     * @return list<list<array{label: string, value: ?string, badge?: string, mono?: bool}>>
     */
    public static function detailRows(ImportBatch $record): array
    {
        $isBmpc = $record->isBmpcMemberImport();
        $count = fn (?int $value): string => number_format((int) $value);
        $dateTime = fn ($value): ?string => $value?->format('M j, Y g:i A');

        $items = array_values(array_filter([
            ['label' => 'Import ID', 'value' => "#{$record->id}", 'mono' => true],
            ['label' => 'File', 'value' => $record->file_name],
            ['label' => 'Imported into', 'value' => ImportBatch::TYPES[$record->type] ?? $record->type],
            ['label' => 'Status', 'value' => ImportBatch::STATUSES[$record->status] ?? $record->status, 'badge' => ImportBatch::statusColor($record->status)],
            ['label' => 'Uploaded by', 'value' => $record->uploader?->name],
            ['label' => 'Uploaded', 'value' => $dateTime($record->created_at)],
            ['label' => 'Completed at', 'value' => $dateTime($record->completed_at)],
            $isBmpc ? null : ['label' => 'Balances as of', 'value' => $record->as_of_date?->format('M j, Y')],
            ['label' => 'Total rows', 'value' => $count($record->total_rows)],
            ['label' => $isBmpc ? 'Skipped' : 'Invalid', 'value' => $count($record->invalid_rows)],
            ['label' => 'New', 'value' => $count($record->new_rows)],
            ['label' => 'Updates', 'value' => $count($record->update_rows)],
            $isBmpc ? null : ['label' => 'Duplicates', 'value' => $count($record->duplicate_rows)],
            $isBmpc ? null : ['label' => 'With warnings', 'value' => $count($record->warning_rows)],
            ['label' => 'Created', 'value' => $count($record->created_count)],
            ['label' => 'Updated', 'value' => $count($record->updated_count)],
        ]));

        $rows = array_chunk($items, 2);
        $rows[] = [['label' => 'Sheets / branches processed', 'value' => implode(', ', array_filter($record->branchNames())) ?: null]];

        if (filled($record->error_message)) {
            $rows[] = [['label' => 'Error', 'value' => $record->error_message]];
        }

        return $rows;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('uploader'))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('file_name')->label('File')->searchable()->wrap()
                    ->description(fn (ImportBatch $record) => ImportBatch::TYPES[$record->type] ?? $record->type),
                Tables\Columns\TextColumn::make('created_at')->label('Uploaded')->dateTime()->sortable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('uploader.name')->label('By')->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('total_rows')->label('Rows')->numeric()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('created_count')->label('New')->numeric()->color('success'),
                Tables\Columns\TextColumn::make('updated_count')->label('Updated')->numeric()->color('info')->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('duplicate_rows')->label('Dupl.')->numeric()->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('invalid_rows')->label('Invalid')->numeric()->color('danger')->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => ImportBatch::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => ImportBatch::statusColor($state)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Imported into')->options(ImportBatch::TYPES),
                Tables\Filters\SelectFilter::make('status')->options(ImportBatch::STATUSES),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton(),
                Tables\Actions\Action::make('resume')
                    ->label(fn (ImportBatch $record) => $record->status === 'awaiting_approval' ? 'Open' : 'Continue')
                    ->icon('heroicon-o-play')
                    ->visible(fn (ImportBatch $record) => ! $record->isBmpcMemberImport() && in_array($record->status, ['analyzed', 'staged', 'awaiting_approval', 'importing'], true) && (auth()->user()?->can('members.import') ?? false))
                    ->url(fn (ImportBatch $record) => MemberResource::getUrl('import', ['batch' => $record->id])),
                Tables\Actions\Action::make('deleteImportedData')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn () => static::canDeleteImportedData())
                    ->requiresConfirmation()
                    ->modalHeading(fn (ImportBatch $record) => "Delete import #{$record->id} and its data?")
                    ->modalDescription(fn (ImportBatch $record) => static::deleteImportedDataWarning($record))
                    ->modalSubmitActionLabel('Delete imported data')
                    ->action(fn (ImportBatch $record) => static::deleteImportedData($record)),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * Undoing an import permanently removes members, so only the Administrator may do it.
     */
    public static function canDeleteImportedData(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    public static function deleteImportedDataWarning(ImportBatch $batch): string
    {
        if ($batch->isBmpcMemberImport()) {
            return 'This permanently deletes '.number_format(app(BmpcMemberImportService::class)->importedDataCount($batch)).' BMPC member(s) that "'.$batch->file_name.'" added'
                .'. Members it only updated stay on the list. The file is also removed from Import History. This cannot be undone.';
        }

        $summary = app(MasterlistImportService::class)->importedDataSummary($batch);

        return 'This permanently deletes '.number_format($summary['members']).' member(s) that "'.$batch->file_name.'" added, together with their savings entries, applications and history'
            .($summary['adjustments'] > 0 ? ', and takes back the balance changes it made to '.number_format($summary['adjustments']).' member(s) that already existed' : '')
            .'. The file is also removed from Import History. This cannot be undone.';
    }

    public static function deleteImportedData(ImportBatch $batch): void
    {
        abort_unless(static::canDeleteImportedData(), 403);

        $fileName = $batch->file_name;

        if ($batch->isBmpcMemberImport()) {
            $deleted = app(BmpcMemberImportService::class)->deleteImportedData($batch);

            Notification::make()
                ->title('Imported data deleted')
                ->body('"'.$fileName.'": '.number_format($deleted).' BMPC member(s) removed.')
                ->success()
                ->send();

            return;
        }

        $summary = app(MasterlistImportService::class)->deleteImportedData($batch);

        Notification::make()
            ->title('Imported data deleted')
            ->body('"'.$fileName.'": '.number_format($summary['members']).' member(s) removed'.($summary['adjustments'] > 0 ? ', '.number_format($summary['adjustments']).' balance adjustment(s) taken back' : '').'.')
            ->success()
            ->send();
    }

    /**
     * @return Builder<ImportBatch>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleToCurrentUser();
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\RowsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportBatches::route('/'),
            'view' => Pages\ViewImportBatch::route('/{record}'),
        ];
    }
}
