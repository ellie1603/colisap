<?php

namespace App\Filament\Resources\ImportBatchResource\Pages;

use App\Filament\Resources\ImportBatchResource;
use App\Filament\Resources\MemberResource;
use App\Services\Masterlist\MasterlistImportService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewImportBatch extends ViewRecord
{
    protected static string $resource = ImportBatchResource::class;

    public function getTitle(): string
    {
        return "Import #{$this->record->id}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('issues')
                ->label('Download issues (CSV)')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn () => app(MasterlistImportService::class)->errorReport($this->record)),
            Actions\Action::make('resume')
                ->label('Continue import')
                ->icon('heroicon-o-play')
                ->visible(fn () => in_array($this->record->status, ['analyzed', 'staged', 'importing'], true) && (auth()->user()?->can('members.import') ?? false))
                ->url(fn () => MemberResource::getUrl('import', ['batch' => $this->record->id])),
            Actions\Action::make('deleteImportedData')
                ->label('Delete imported data')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn () => ImportBatchResource::canDeleteImportedData())
                ->requiresConfirmation()
                ->modalHeading(fn () => "Delete import #{$this->record->id} and its data?")
                ->modalDescription(fn () => ImportBatchResource::deleteImportedDataWarning($this->record))
                ->modalSubmitActionLabel('Delete imported data')
                ->action(function () {
                    ImportBatchResource::deleteImportedData($this->record);

                    $this->redirect(ImportBatchResource::getUrl('index'));
                }),
        ];
    }
}
