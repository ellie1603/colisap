<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * @var list<string>
     */
    private array $permissions = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['permission_groups'] = $this->record->permissions->pluck('name')
            ->groupBy(fn (string $name) => ucfirst(explode('.', $name)[0]))
            ->map->values()->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->permissions = collect($data['permission_groups'] ?? [])->flatten()->values()->all();
        unset($data['permission_groups']);

        return $data;
    }

    protected function afterSave(): void
    {
        RoleResource::syncAndAudit($this->record, $this->permissions);
    }
}
