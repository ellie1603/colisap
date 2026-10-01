<?php

namespace App\Filament\Resources\ApplicationResource\Pages;

use App\Filament\Resources\ApplicationResource;
use App\Filament\Resources\MemberResource;
use App\Services\Policy\PolicySettings;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class EditApplication extends EditRecord
{
    protected static string $resource = ApplicationResource::class;

    protected function getHeaderActions(): array
    {
        $canDecide = fn () => $this->record->status === 'pending' && (Auth::user()?->can('applications.manage') ?? false);

        return [
            Actions\Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible($canDecide)
                ->form([
                    Forms\Components\DatePicker::make('approved_at')->label('Approval date')->default(now())->maxDate(now())->required()
                        ->helperText(fn () => 'The member becomes ACTIVE '.app(PolicySettings::class)->int('effectivity_days').' days after this date.'),
                ])
                ->modalDescription(fn () => $this->record->missingRequirements() === []
                    ? 'All qualification requirements are checked.'
                    : 'Unchecked requirements: '.implode(', ', $this->record->missingRequirements()).'. Save the checklist first if these were verified.')
                ->action(function (array $data) {
                    if ($this->record->missingRequirements() !== []) {
                        Notification::make()->title('Requirements incomplete')
                            ->body('Tick every qualification requirement (and save) before approving.')
                            ->danger()->send();

                        return;
                    }

                    $this->record->approve(Carbon::parse($data['approved_at']));

                    Notification::make()->title('Application approved')
                        ->body('Effective on '.$this->record->member->effectivityDate()->toFormattedDateString())
                        ->success()->send();

                    $this->redirect(MemberResource::getUrl('view', ['record' => $this->record->member]));
                }),
            Actions\Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible($canDecide)
                ->form([Forms\Components\Textarea::make('reason')->required()])
                ->requiresConfirmation()
                ->action(function (array $data) {
                    $this->record->reject($data['reason']);
                    Notification::make()->title('Application rejected')->send();
                    $this->refreshFormData(['status']);
                }),
            Actions\Action::make('member')
                ->label('Member profile')
                ->color('gray')
                ->url(fn () => MemberResource::getUrl('view', ['record' => $this->record->member])),
        ];
    }
}
