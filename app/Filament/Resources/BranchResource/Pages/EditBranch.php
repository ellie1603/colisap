<?php

namespace App\Filament\Resources\BranchResource\Pages;

use App\Filament\Concerns\ReturnsToPreviousPage;
use App\Filament\Resources\BranchResource;
use Filament\Resources\Pages\EditRecord;

class EditBranch extends EditRecord
{
    use ReturnsToPreviousPage;

    protected static string $resource = BranchResource::class;
}
