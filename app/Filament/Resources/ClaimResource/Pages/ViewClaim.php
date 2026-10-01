<?php

namespace App\Filament\Resources\ClaimResource\Pages;

use App\Filament\Resources\ClaimResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewClaim extends ViewRecord
{
    protected static string $resource = ClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ClaimResource::workflowActions(Actions\Action::class),
            Actions\EditAction::make(),
            Actions\Action::make('print')
                ->label('Print claim')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn () => route('claims.print', $this->record), true),
        ];
    }
}
