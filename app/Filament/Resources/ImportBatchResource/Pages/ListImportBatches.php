<?php

namespace App\Filament\Resources\ImportBatchResource\Pages;

use App\Filament\Resources\ImportBatchResource;
use App\Filament\Resources\MemberResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListImportBatches extends ListRecords
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('import')
                ->label('Import Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn () => auth()->user()?->can('members.import') ?? false)
                ->url(MemberResource::getUrl('import')),
        ];
    }
}
