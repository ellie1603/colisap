<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @var list<string>
     */
    private array $rolesBefore = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->hidden(fn () => $this->record->is(Auth::user())),
        ];
    }

    protected function beforeSave(): void
    {
        $this->rolesBefore = $this->record->roles()->pluck('name')->sort()->values()->all();
    }

    protected function afterSave(): void
    {
        $rolesAfter = $this->record->roles()->pluck('name')->sort()->values()->all();

        if ($rolesAfter !== $this->rolesBefore) {
            $this->record->recordAudit('roles_changed', ['roles' => $this->rolesBefore], ['roles' => $rolesAfter], 'User roles changed');
        }
    }
}
