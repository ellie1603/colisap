<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * @var list<string>
     */
    private array $permissions = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->permissions = collect($data['permission_groups'] ?? [])->flatten()->values()->all();
        unset($data['permission_groups']);

        return [...$data, 'guard_name' => 'web'];
    }

    protected function afterCreate(): void
    {
        RoleResource::syncAndAudit($this->record, $this->permissions);
    }
}
