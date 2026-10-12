<?php

namespace App\Filament\Resources\ApplicationResource\Pages;

use App\Filament\Concerns\ReturnsToPreviousPage;
use App\Filament\Resources\ApplicationResource;
use App\Filament\Resources\MemberResource;
use App\Models\Branch;
use App\Models\Member;
use App\Services\Policy\PolicySettings;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class EditApplication extends EditRecord
{
    use ReturnsToPreviousPage;

    protected static string $resource = ApplicationResource::class;

    /**
     * A pending application stays open after its checklist is saved, because the next step — Approve or Reject —
     * is on this page. Once decided, saving returns to the page it was opened from like every other form.
     */
    protected function getRedirectUrl(): ?string
    {
        return $this->record->status === 'pending'
            ? null
            : $this->previousPageOr(ApplicationResource::getUrl('index'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ApplicationResource::withMemberDetails($this->record, $data);
    }

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

                    // The applicant becomes a member now; stop if someone with this Acct. Number was added meanwhile.
                    if ($this->record->member_id === null && Branch::withoutRestriction(fn () => Member::withTrashed()->where('account_no', $this->record->account_no)->exists())) {
                        Notification::make()->title('This Acct. Number is already a member')
                            ->body('A member with Acct. Number '.$this->record->account_no.' now exists, so this application cannot be approved as a new member.')
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
            Actions\Action::make('reopen')
                ->label('Reopen application')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn () => $this->record->status === 'rejected' && (Auth::user()?->can('applications.manage') ?? false))
                ->requiresConfirmation()
                ->modalDescription('Puts this rejected application back to Pending so it can be reviewed and approved. Use this instead of filing a second application for the same member.')
                ->modalSubmitActionLabel('Reopen')
                ->action(function () {
                    $this->record->reopen();
                    Notification::make()->title('Application reopened')->body('It is pending again and can now be approved or rejected.')->success()->send();
                    $this->refreshFormData(['status']);
                }),
            Actions\Action::make('member')
                ->label('Member profile')
                ->color('gray')
                ->visible(fn () => $this->record->member !== null)
                ->url(fn () => MemberResource::getUrl('view', ['record' => $this->record->member])),
        ];
    }
}
