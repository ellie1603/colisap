<?php

namespace App\Filament\Resources\ApplicationResource\Pages;

use App\Filament\Resources\ApplicationResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;

class ListApplications extends ListRecords
{
    protected static string $resource = ApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New application')
                ->modalHeading('New application')
                ->modalSubmitActionLabel('Create application')
                ->modalWidth(MaxWidth::FiveExtraLarge)
                ->createAnother(false)
                ->mutateFormDataUsing(fn (array $data): array => ApplicationResource::withCreationDefaults($data))
                ->successNotification(fn (Notification $notification) => $notification
                    ->title('Application filed')
                    ->body('It is pending. The applicant is added to Members once the Administrator approves it.')),
        ];
    }
}
