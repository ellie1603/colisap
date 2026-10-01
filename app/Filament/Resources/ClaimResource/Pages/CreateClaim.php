<?php

namespace App\Filament\Resources\ClaimResource\Pages;

use App\Filament\Resources\ClaimResource;
use Filament\Resources\Pages\CreateRecord;

class CreateClaim extends CreateRecord
{
    protected static string $resource = ClaimResource::class;

    protected function getRedirectUrl(): string
    {
        return ClaimResource::getUrl('view', ['record' => $this->record]);
    }
}
