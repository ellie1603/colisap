<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New staff account')
                ->modalHeading('New staff account')
                ->modalSubmitActionLabel('Create account')
                ->createAnother(false)
                ->after(fn (User $record) => $record->recordAudit('roles_changed', null, ['roles' => $record->roles()->pluck('name')->all()], 'User created'))
                ->successNotificationTitle('Staff account created'),
        ];
    }
}
