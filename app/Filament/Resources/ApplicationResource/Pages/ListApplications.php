<?php

namespace App\Filament\Resources\ApplicationResource\Pages;

use App\Filament\Resources\ApplicationResource;
use App\Models\Application;
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
                ->label('New transaction')
                ->modalHeading('New transaction')
                ->modalDescription('Add a new or an already-active member, upgrade a member, or re-apply a returning member.')
                ->modalSubmitActionLabel('Save transaction')
                ->modalWidth(MaxWidth::FiveExtraLarge)
                ->createAnother(false)
                ->mutateFormDataUsing(fn (array $data): array => ApplicationResource::withCreationDefaults($data))
                ->successNotification(fn (Notification $notification, Application $record) => $notification
                    ->title('Transaction saved')
                    ->body(match ($record->type) {
                        'upgrade' => 'It is pending. The upgrade waiting period starts once the Administrator approves it.',
                        'reapplication' => 'It is pending. The member is reinstated once the Administrator approves it.',
                        default => 'It is pending. The member is added to Members once the Administrator approves it.',
                    })),
        ];
    }
}
