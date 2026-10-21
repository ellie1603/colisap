<?php

namespace App\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\ApplicationResource;
use App\Filament\Resources\MemberResource;
use App\Models\Application;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Services\Colisap\MemberStatusEngine;
use App\Services\Policy\PolicySettings;
use App\Services\Privacy\MemberPersonalData;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

class ViewMember extends ViewRecord
{
    protected static string $resource = MemberResource::class;

    public function getTitle(): string
    {
        return "{$this->record->account_no} — {$this->record->account_name}";
    }

    #[On('member-ledger-updated')]
    public function refreshMember(): void
    {
        $this->record->refresh();
    }

    protected function getHeaderActions(): array
    {
        $can = fn (string $permission) => Auth::user()?->can($permission) ?? false;
        $policy = app(PolicySettings::class);

        return [
            Actions\EditAction::make(),
            Actions\ActionGroup::make([
                Actions\Action::make('recordSavings')
                    ->label('Record savings transaction')
                    ->icon('heroicon-o-banknotes')
                    ->visible(fn () => $can('savings.manage') && ! $this->record->trashed())
                    ->form([
                        Forms\Components\Select::make('type')
                            ->options(['deposit' => 'Deposit', 'withdrawal' => 'Withdrawal'])
                            ->default('deposit')
                            ->required(),
                        Forms\Components\TextInput::make('amount')->numeric()->minValue(0.01)->prefix('₱')->required(),
                        Forms\Components\DatePicker::make('transaction_date')->default(now())->maxDate(now())->required(),
                        Forms\Components\TextInput::make('reference_no')->label('OR / Reference No.')->maxLength(255),
                        Forms\Components\Textarea::make('remarks')->rows(2),
                    ])
                    ->action(function (array $data) {
                        SavingsTransaction::create([...$data, 'member_id' => $this->record->id]);
                        $this->record->refresh();

                        Notification::make()->title('Savings transaction recorded')
                            ->body('New balance: ₱'.number_format((float) $this->record->savings_balance, 2))
                            ->success()->send();
                    }),
                Actions\Action::make('requestUpgrade')
                    ->label('Request '.Member::higherCategoryLabel().' upgrade')
                    ->icon('heroicon-o-arrow-trending-up')
                    ->visible(fn () => ($can('members.update_colisap') || $can('monitoring.manage'))
                        && $this->record->category === '40000'
                        && $this->record->status === 'active'
                        && ! $this->record->category_upgrade_requested_at)
                    ->form([
                        Forms\Components\DatePicker::make('requested_at')->label('Request date')->default(now())->maxDate(now())->required(),
                    ])
                    ->modalDescription(fn () => 'The member becomes eligible for '.Member::higherCategoryLabel().' after '.$policy->int('upgrade_wait_days').' days (Policy III.3) and must keep ₱'.number_format($policy->decimal('min_balance_60k'), 2).' in savings.')
                    ->action(function (array $data) {
                        $this->record->requestCategoryUpgrade(Carbon::parse($data['requested_at']));

                        Notification::make()->title('Upgrade requested')
                            ->body('Eligible on '.$this->record->upgradeEligibleDate()->toFormattedDateString())
                            ->success()->send();
                    }),
                Actions\Action::make('applyUpgrade')
                    ->label('Apply '.Member::higherCategoryLabel().' upgrade')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn () => $can('monitoring.manage') && $this->record->upgradeStatus() === 'eligible')
                    ->requiresConfirmation()
                    ->action(function () {
                        if (! $this->record->applyCategoryUpgradeIfEligible()) {
                            Notification::make()->title('Upgrade not applied')
                                ->body('Savings must be at least ₱'.number_format(app(PolicySettings::class)->decimal('min_balance_60k'), 2).' to move to '.Member::higherCategoryLabel().'.')
                                ->danger()->send();

                            return;
                        }

                        $this->record->refreshStatus();
                        Notification::make()->title('Member moved to '.Member::higherCategoryLabel())->success()->send();
                    }),
                Actions\Action::make('issueNotice')
                    ->label('Issue replenishment notice')
                    ->icon('heroicon-o-bell-alert')
                    ->color('warning')
                    ->visible(fn () => $can('monitoring.manage')
                        && ! in_array($this->record->status, Member::TERMINAL_STATUSES, true)
                        && ! $this->record->meetsMinimumBalance()
                        && ! $this->record->openReplenishmentNotice()->exists())
                    ->form([
                        Forms\Components\DatePicker::make('notice_date')->default(now())->maxDate(now())->required()
                            ->helperText(fn () => 'The member has '.$policy->int('replenishment_days').' days from this date to replenish.'),
                    ])
                    ->action(function (array $data) {
                        $notice = app(MemberStatusEngine::class)->issueNotice($this->record, Carbon::parse($data['notice_date']));

                        Notification::make()->title('Replenishment notice issued')
                            ->body('Deadline: '.$notice->deadline->toFormattedDateString())
                            ->warning()->send();
                    }),
                Actions\Action::make('withdraw')
                    ->label('Record voluntary withdrawal')
                    ->icon('heroicon-o-arrow-left-start-on-rectangle')
                    ->color('danger')
                    ->visible(fn () => $can('applications.manage') && ! in_array($this->record->status, Member::TERMINAL_STATUSES, true))
                    ->modalDescription('Policy IX: a participant may withdraw by submitting a letter to Management. The member record and history are kept.')
                    ->form([
                        Forms\Components\DatePicker::make('withdrawn_at')->label('Withdrawal date')->default(now())->maxDate(now())->required(),
                        Forms\Components\Textarea::make('withdrawal_reason')->label('Reason')->required()->rows(2),
                        Forms\Components\FileUpload::make('withdrawal_document')
                            ->label('Withdrawal letter')
                            ->disk('local')
                            ->directory('withdrawals')
                            ->visibility('private')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                            ->maxSize(10240)
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (array $data) {
                        $this->record->withChangeReason('Voluntary withdrawal (Policy IX): '.$data['withdrawal_reason'])
                            ->fill([...$data, 'status' => 'withdrawn'])
                            ->save();
                        $this->record->refreshStatus();

                        Notification::make()->title('Withdrawal recorded')->success()->send();
                    }),
                Actions\Action::make('reapply')
                    ->label('Start re-application')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->visible(fn () => $can('applications.manage')
                        && Application::blockingReason($this->record, 'reapplication') === null)
                    ->modalDescription(fn () => 'Policy X: the member goes through the same qualification process as a new member. Re-application fee: ₱'.number_format($policy->decimal('reapplication_fee'), 2).'. Previous history is kept.')
                    ->form([
                        Forms\Components\DatePicker::make('application_date')->default(now())->maxDate(now())->required(),
                        Forms\Components\Select::make('category')->options(Member::categoryOptions())->default('40000')->required(),
                        Forms\Components\TextInput::make('or_number')->label('OR number')->required()->maxLength(50)->regex('/^[0-9A-Za-z\-\/ ]+$/')
                            ->helperText('Official receipt number of the re-application fee.'),
                    ])
                    ->action(function (array $data) use ($policy) {
                        $application = Application::create([
                            ...$data,
                            'member_id' => $this->record->id,
                            'type' => 'reapplication',
                            'status' => 'pending',
                            'fee_amount' => $policy->decimal('reapplication_fee'),
                            'fee_paid' => true,
                            'previous_status' => $this->record->status,
                        ]);

                        Notification::make()->title('Re-application created')->success()->send();
                        $this->redirect(ApplicationResource::getUrl('edit', ['record' => $application]));
                    }),
                Actions\Action::make('recalculate')
                    ->label('Recalculate status')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn () => $can('members.update'))
                    ->action(function () {
                        $changed = $this->record->refreshStatus();

                        Notification::make()
                            ->title($changed ? 'Status updated to '.Member::STATUSES[$this->record->status] : 'Status is up to date')
                            ->success()->send();
                    }),
                Actions\Action::make('certificate')
                    ->label('Print COLISAP certificate')
                    ->icon('heroicon-o-printer')
                    ->url(fn () => route('members.certificate', $this->record), true)
                    ->visible(fn () => $this->record->approval_date !== null),
                Actions\Action::make('exportPersonalData')
                    ->label('Export personal data')
                    ->icon('heroicon-o-document-arrow-down')
                    ->visible(fn () => $can('members.privacy'))
                    ->requiresConfirmation()
                    ->modalDescription('Downloads everything COLISAP holds about this member (details, applications, savings, notices and change history) — for a request under the Data Privacy Act. Give it only to the member.')
                    ->modalSubmitActionLabel('Download')
                    ->action(fn () => response()->streamDownload(
                        fn () => print (json_encode(app(MemberPersonalData::class)->export($this->record), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                        "personal-data-{$this->record->account_no}.json",
                        ['Content-Type' => 'application/json'],
                    )),
                Actions\Action::make('erase')
                    ->label('Erase permanently')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn () => $can('members.privacy') && $this->record->trashed())
                    ->modalHeading('Erase this member permanently?')
                    ->modalDescription('For a request under the Data Privacy Act. The member, their applications, savings, notices, history and imported rows are deleted for good and cannot be restored. Only a note that an erasure happened is kept.')
                    ->modalSubmitActionLabel('Erase permanently')
                    ->form(fn () => [
                        Forms\Components\Textarea::make('reason')->label('Reason / request reference')->required()->rows(2)->maxLength(500),
                        Forms\Components\TextInput::make('confirm_account_no')->label('Type the Acct. Number to confirm')
                            ->required()->in([$this->record->account_no])
                            ->validationMessages(['in' => 'That is not this member\'s Acct. Number.']),
                    ])
                    ->action(function (array $data) {
                        app(MemberPersonalData::class)->erase($this->record, $data['reason']);

                        Notification::make()->title('Member erased permanently')->success()->send();
                        $this->redirect(MemberResource::getUrl('index'));
                    }),
            ])
                ->label('Actions')
                ->icon('heroicon-m-ellipsis-vertical')
                ->button(),
        ];
    }
}
