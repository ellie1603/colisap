<?php

namespace App\Filament\Resources\ContributionResource\Pages;

use App\Filament\Resources\ContributionResource;
use Filament\Resources\Pages\ListRecords;

class ListContributions extends ListRecords
{
    protected static string $resource = ContributionResource::class;

    public function getSubheading(): ?string
    {
        return 'Contributions are generated from approved mortuary claims (Mortuary Claims → Generate contributions).';
    }
}
