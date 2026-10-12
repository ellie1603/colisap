<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;
use Spatie\Permission\Models\Role;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        // The permission checkboxes are not columns of the role: they are set aside before it is saved and applied after.
        $permissions = [];

        return [
            Actions\CreateAction::make()
                ->modalHeading('New role')
                ->modalSubmitActionLabel('Create role')
                ->modalWidth(MaxWidth::FourExtraLarge)
                ->createAnother(false)
                ->mutateFormDataUsing(function (array $data) use (&$permissions): array {
                    $permissions = collect($data['permission_groups'] ?? [])->flatten()->values()->all();
                    unset($data['permission_groups']);

                    return [...$data, 'guard_name' => 'web'];
                })
                ->after(function (Role $record) use (&$permissions): void {
                    RoleResource::syncAndAudit($record, $permissions);
                })
                ->successNotificationTitle('Role created'),
        ];
    }
}
