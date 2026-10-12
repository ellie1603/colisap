<?php

namespace App\Filament\Pages;

use App\Filament\Resources\MemberResource;
use App\Models\ImportBatch;
use App\Services\Masterlist\MasterlistImportService;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Excel imports sent by the branches wait here. Nothing in them reaches the member data until the
 * Administrator has reviewed the file and approved it; a rejected import is returned with the reason.
 */
class ImportApprovals extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'Data & Reports';

    protected static ?string $navigationLabel = 'Import Approvals';

    protected static ?string $title = 'Import Approvals';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.import-approvals';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('imports.approve') ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = ImportBatch::where('status', 'awaiting_approval')->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ImportBatch::query()->where('status', 'awaiting_approval')->with('uploader.branch'))
            ->heading('Imports waiting for your approval')
            ->description('Review the rows of a file before approving it. Approving writes its members into the system; rejecting sends it back with your reason.')
            ->emptyStateHeading('Nothing is waiting for approval')
            ->emptyStateDescription('When a branch sends an Excel import, it appears here.')
            ->columns([
                Tables\Columns\TextColumn::make('file_name')->label('File')->wrap()->searchable()
                    ->description(fn (ImportBatch $record) => implode(', ', $record->branchNames())),
                Tables\Columns\TextColumn::make('uploader.name')->label('Sent by')
                    ->description(fn (ImportBatch $record) => $record->uploader?->branch?->name),
                Tables\Columns\TextColumn::make('updated_at')->label('Sent')->since()->sortable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('new_rows')->label('New members')->numeric()->alignEnd()->color('success'),
                Tables\Columns\TextColumn::make('update_rows')->label('Updates')->numeric()->alignEnd()->color('info'),
                Tables\Columns\TextColumn::make('invalid_rows')->label('Invalid')->numeric()->alignEnd()->visibleFrom('lg')
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray'),
            ])
            ->actions([
                Tables\Actions\Action::make('review')
                    ->label('Review rows')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (ImportBatch $record) => MemberResource::getUrl('import', ['batch' => $record->id])),
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (ImportBatch $record) => "Approve \"{$record->file_name}\"?")
                    ->modalDescription(fn (ImportBatch $record) => number_format($record->new_rows).' new member(s) will be added and '.number_format($record->update_rows).' existing member(s) updated. The import starts right away on the next screen — keep it open until it finishes.')
                    ->modalSubmitActionLabel('Approve & import')
                    ->action(function (ImportBatch $record) {
                        try {
                            app(MasterlistImportService::class)->approve($record);
                        } catch (RuntimeException $exception) {
                            Notification::make()->title('This import cannot be approved')->body($exception->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        $this->redirect(MemberResource::getUrl('import', ['batch' => $record->id]));
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading(fn (ImportBatch $record) => "Reject \"{$record->file_name}\"?")
                    ->modalDescription('Nothing from this file is added to the system. The branch sees your reason in Import History.')
                    ->modalSubmitActionLabel('Reject import')
                    ->form([Textarea::make('reason')->label('Reason')->required()->rows(3)->maxLength(500)])
                    ->action(function (ImportBatch $record, array $data) {
                        app(MasterlistImportService::class)->reject($record, $data['reason']);

                        Notification::make()->title('Import rejected')->body('Nothing from this file was added to the system.')->success()->send();
                    }),
            ])
            ->defaultSort('updated_at')
            ->paginated(false);
    }
}
