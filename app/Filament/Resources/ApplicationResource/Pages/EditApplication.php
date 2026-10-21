<?php

namespace App\Filament\Resources\ApplicationResource\Pages;

use App\Exceptions\ApplicationAlreadyDecided;
use App\Filament\Concerns\DetectsConcurrentEdits;
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
use RuntimeException;

class EditApplication extends EditRecord
{
    use DetectsConcurrentEdits, ReturnsToPreviousPage;

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
                        ->helperText(fn () => match ($this->record->type) {
                            'upgrade' => 'The upgrade waiting period counts from the request date ('.$this->record->application_date?->toFormattedDateString().').',
                            'active' => 'Their status counts from the original approval date ('.$this->record->member_since?->toFormattedDateString().').',
                            default => 'The member becomes ACTIVE '.app(PolicySettings::class)->int('effectivity_days').' days after this date.',
                        }),
                ])
                ->modalDescription(fn () => match ($this->record->type) {
                    'upgrade' => 'The member’s request to move to the '.Member::higherCategoryLabel().' category is recorded and their upgrade waiting period starts.',
                    'active' => 'The member is added to Members as already enrolled, with their original approval date.',
                    'reapplication' => 'The returning member is reinstated and starts the waiting period again.',
                    default => 'The applicant is added to Members and starts the waiting period.',
                })
                ->action(function (array $data) {
                    // The applicant becomes a member now; stop if someone with this Acct. Number was added meanwhile.
                    if ($this->record->member_id === null && $this->record->type !== 'upgrade' && Branch::withoutRestriction(fn () => Member::withTrashed()->where('account_no', $this->record->account_no)->exists())) {
                        Notification::make()->title('This Acct. Number is already a member')
                            ->body('A member with Acct. Number '.$this->record->account_no.' now exists, so this application cannot be approved as a new member.')
                            ->danger()->send();

                        return;
                    }

                    if (! $this->decided(fn () => $this->record->approve(Carbon::parse($data['approved_at'])))) {
                        return;
                    }

                    $member = $this->record->member->fresh();

                    Notification::make()->title('Transaction approved')
                        ->body($this->record->type === 'upgrade'
                            ? 'Eligible for '.Member::higherCategoryLabel().' on '.$member->upgradeEligibleDate()?->toFormattedDateString()
                            : 'Status: '.Member::STATUSES[$member->status].' · effective on '.$member->effectivityDate()?->toFormattedDateString())
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
                    if (! $this->decided(fn () => $this->record->reject($data['reason']))) {
                        return;
                    }

                    Notification::make()->title('Transaction rejected')->send();
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
                    if (! $this->decided(fn () => $this->record->reopen())) {
                        return;
                    }

                    Notification::make()->title('Transaction reopened')->body('It is pending again and can now be approved or rejected.')->success()->send();
                    $this->refreshFormData(['status']);
                }),
            Actions\Action::make('member')
                ->label('Member profile')
                ->color('gray')
                ->visible(fn () => $this->record->member !== null)
                ->url(fn () => MemberResource::getUrl('view', ['record' => $this->record->member])),
        ];
    }

    /**
     * Runs a decision; when someone else decided first, says so and shows the current status instead of failing.
     */
    private function decided(callable $decision): bool
    {
        try {
            $decision();
            $this->rememberCurrentVersion();

            return true;
        } catch (ApplicationAlreadyDecided $exception) {
            Notification::make()->title('Already decided')->body($exception->getMessage())->warning()->persistent()->send();
            $this->record->refresh();
            $this->refreshFormData(['status']);

            return false;
        } catch (RuntimeException $exception) {
            // E.g. the member of an upgrade request is no longer an active basic-category member.
            Notification::make()->title('This transaction cannot be approved')->body($exception->getMessage())->danger()->persistent()->send();

            return false;
        }
    }
}
