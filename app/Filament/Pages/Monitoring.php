<?php

namespace App\Filament\Pages;

use App\Filament\Resources\MemberResource;
use App\Filament\Tables\TableFilters;
use App\Models\Branch;
use App\Models\Member;
use App\Models\ReplenishmentNotice;
use App\Services\Colisap\MemberStatusEngine;
use App\Services\Colisap\MonitoringService;
use App\Services\Policy\PolicySettings;
use App\Services\Sms\MonitoringSms;
use App\Services\Sms\SmsGateway;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Operational monitoring of the COLISAP policy timelines.
 */
class Monitoring extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-eye';

    protected static ?string $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.monitoring';

    public const TABS = [
        'effectivity' => ['180-day effectivity', 'heroicon-m-clock'],
        'upgrades' => ['90-day upgrades', 'heroicon-m-arrow-trending-up'],
        'qualified' => ['Can upgrade', 'heroicon-m-sparkles'],
        'replenishment' => ['15-day replenishment', 'heroicon-m-bell-alert'],
        'dormancy' => ['Dormancy', 'heroicon-m-pause-circle'],
    ];

    #[Url]
    public string $tab = 'effectivity';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('monitoring.view') ?? false;
    }

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'effectivity';
        }
    }

    /**
     * Switch views in place (no page reload); each view has its own columns, filters and sort, so the table is rebuilt.
     */
    public function switchTab(string $tab): void
    {
        if (! array_key_exists($tab, self::TABS) || $tab === $this->tab) {
            return;
        }

        $this->tab = $tab;
        $this->tableSearch = '';
        $this->tableSortColumn = null;
        $this->tableSortDirection = null;
        $this->deselectAllTableRecords();
        $this->resetTable();
    }

    private function policy(): PolicySettings
    {
        return app(PolicySettings::class);
    }

    private function canManage(): bool
    {
        return Auth::user()?->can('monitoring.manage') ?? false;
    }

    private function canSendSms(): bool
    {
        return Auth::user()?->can('monitoring.send_sms') ?? false;
    }

    /**
     * @return array<string, int>
     */
    public function tabCounts(): array
    {
        $monitoring = app(MonitoringService::class);

        return [
            'effectivity' => Member::where('status', 'waiting')->count(),
            'upgrades' => $monitoring->upgradeQuery('eligible')->count() + $monitoring->upgradeQuery('pending')->count(),
            'qualified' => $monitoring->qualifiedForUpgrade()->count(),
            'replenishment' => ReplenishmentNotice::where('status', 'open')->count(),
            'dormancy' => Member::where('status', 'dormant')->count(),
        ];
    }

    public function table(Table $table): Table
    {
        $table->searchPlaceholder('Search name or Acct. Number');

        return match ($this->tab) {
            'upgrades' => $this->upgradesTable($table),
            'qualified' => $this->qualifiedTable($table),
            'replenishment' => $this->replenishmentTable($table),
            'dormancy' => $this->dormancyTable($table),
            default => $this->effectivityTable($table),
        };
    }

    /**
     * The filters every member view shares: branch, category and segmentation.
     *
     * @return list<Tables\Filters\BaseFilter>
     */
    private function memberFilters(): array
    {
        return [
            $this->branchFilter(),
            TableFilters::category(),
            TableFilters::segmentation(),
        ];
    }

    /**
     * @return list<Tables\Columns\Column>
     */
    private function memberColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('account_no')->label('Acct. Number')->fontFamily('mono')->searchable(),
            Tables\Columns\TextColumn::make('account_name')->label('Account Name')->wrap()
                ->searchable(query: fn (Builder $query, string $search) => $query->searchName($search)),
            Tables\Columns\TextColumn::make('branch.name')->label('Branch')->visibleFrom('md'),
            Tables\Columns\TextColumn::make('category')->badge()->formatStateUsing(fn (string $state) => Member::categoryLabel($state))->visibleFrom('lg'),
        ];
    }

    private function branchFilter(string $relation = ''): Tables\Filters\SelectFilter
    {
        return Tables\Filters\SelectFilter::make('branch')
            ->options(fn () => Branch::options())
            ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $branch) => $relation
                ? $query->whereHas($relation, fn (Builder $q) => $q->where('branch_id', $branch))
                : $query->where('branch_id', $branch)));
    }

    private function effectivityTable(Table $table): Table
    {
        $days = $this->policy()->int('effectivity_days');

        return $table
            ->query(Member::query()->with('branch')->where('status', 'waiting'))
            ->heading('Members in the '.$days.'-day waiting period')
            ->description('Effectivity date = approval date + '.$days.' days (Policy III.1). Members become ACTIVE automatically on that date.')
            ->columns([
                ...$this->memberColumns(),
                Tables\Columns\TextColumn::make('approval_date')->date()->sortable()->placeholder('Not approved'),
                Tables\Columns\TextColumn::make('effectivity')->label('Effective on')
                    ->state(fn (Member $record) => $record->effectivityDate())->date(),
                Tables\Columns\TextColumn::make('days_left')->label('Days remaining')
                    ->state(fn (Member $record) => $record->daysUntilEffective())
                    ->badge()
                    ->color(fn (?int $state) => match (true) {
                        $state === null => 'gray',
                        $state <= 30 => 'success',
                        $state <= 60 => 'info',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('window')->label('Becoming effective within')->placeholder('Any effectivity date')
                    ->options(['30' => 'Effective within 30 days', '60' => 'Effective within 60 days', '90' => 'Effective within 90 days'])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $window) => $query
                        ->whereNotNull('approval_date')
                        ->where('approval_date', '<=', Carbon::today()->subDays($days)->addDays((int) $window)->toDateString()))),
                ...$this->memberFilters(),
            ])
            ->headerActions([$this->smsToAllAction()])
            ->actions([$this->smsAction()])
            ->bulkActions([$this->smsBulkAction()])
            ->defaultSort('approval_date')
            ->recordUrl(fn (Member $record) => MemberResource::getUrl('view', ['record' => $record]));
    }

    private function upgradesTable(Table $table): Table
    {
        $wait = $this->policy()->int('upgrade_wait_days');
        $min60 = $this->policy()->decimal('min_balance_60k');

        return $table
            ->query(Member::query()->with('branch')->participating()->where('category', '40000')->whereNotNull('category_upgrade_requested_at'))
            ->heading(Member::basicCategoryLabel().' → '.Member::higherCategoryLabel().' upgrade requests')
            ->description("Eligible {$wait} days after the request (Policy III.3). Moving to ".Member::higherCategoryLabel().' also requires ₱'.number_format($min60, 2).' savings.')
            ->columns([
                ...$this->memberColumns(),
                Tables\Columns\TextColumn::make('category_upgrade_requested_at')->label('Requested')->date()->sortable(),
                Tables\Columns\TextColumn::make('eligible_on')->label('Eligible on')->state(fn (Member $record) => $record->upgradeEligibleDate())->date(),
                Tables\Columns\TextColumn::make('upgrade_state')->label('Upgrade status')
                    ->state(fn (Member $record) => $record->upgradeStatus() === 'eligible' ? 'Eligible' : 'Waiting ('.max(0, (int) Carbon::today()->diffInDays($record->upgradeEligibleDate(), false)).' days)')
                    ->badge()
                    ->color(fn (string $state) => $state === 'Eligible' ? 'success' : 'info'),
                Tables\Columns\TextColumn::make('savings_balance')->money('PHP')
                    ->color(fn (Member $record) => (float) $record->savings_balance >= $min60 ? 'success' : 'danger'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('state')->label('Upgrade status')
                    ->options(['eligible' => 'Eligible now', 'pending' => 'Still waiting', 'soon' => 'Eligible within '.$this->policy()->int('alert_window_days').' days'])
                    ->query(function (Builder $query, array $data) use ($wait) {
                        $cutoff = Carbon::today()->subDays($wait);

                        return match ($data['value'] ?? null) {
                            'eligible' => $query->where('category_upgrade_requested_at', '<=', $cutoff->toDateString()),
                            'pending' => $query->where('category_upgrade_requested_at', '>', $cutoff->toDateString()),
                            'soon' => $query->where('category_upgrade_requested_at', '>', $cutoff->toDateString())
                                ->where('category_upgrade_requested_at', '<=', $cutoff->copy()->addDays($this->policy()->int('alert_window_days'))->toDateString()),
                            default => $query,
                        };
                    }),
                ...$this->memberFilters(),
            ])
            ->headerActions([$this->smsToAllAction()])
            ->actions([
                $this->smsAction(),
                Tables\Actions\Action::make('apply')
                    ->label('Apply '.Member::higherCategoryLabel())
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Member $record) => $this->canManage() && $record->upgradeStatus() === 'eligible')
                    ->requiresConfirmation()
                    ->action(fn (Member $record) => $this->applyUpgrades(new Collection([$record]))),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('applyAll')
                    ->label('Apply '.Member::higherCategoryLabel().' to selected')
                    ->icon('heroicon-o-check-badge')
                    ->visible(fn () => $this->canManage())
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => $this->applyUpgrades($records)),
                $this->smsBulkAction(),
            ])
            ->defaultSort('category_upgrade_requested_at')
            ->recordUrl(fn (Member $record) => MemberResource::getUrl('view', ['record' => $record]));
    }

    /**
     * Active basic-category members whose savings already meet the higher category's maintaining balance and who have not asked
     * to upgrade yet — the people worth inviting. Recording a request moves them to the 90-day upgrades view.
     */
    private function qualifiedTable(Table $table): Table
    {
        $min60 = $this->policy()->decimal('min_balance_60k');
        $wait = $this->policy()->int('upgrade_wait_days');

        return $table
            ->query(app(MonitoringService::class)->qualifiedForUpgrade()->with('branch'))
            ->heading(Member::basicCategoryLabel().' members who can upgrade to '.Member::higherCategoryLabel())
            ->description('Active '.Member::basicCategoryLabel().' members with at least ₱'.number_format($min60, 2)." in savings and no upgrade request yet. Send them an SMS invitation; once a member applies, record the request and the {$wait}-day waiting period starts (Policy III.3).")
            ->columns([
                ...$this->memberColumns(),
                Tables\Columns\TextColumn::make('contact_number')->label('Mobile number')->placeholder('None on file')->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('savings_balance')->money('PHP')->sortable()->color('success'),
                Tables\Columns\TextColumn::make('above_minimum')->label('Above '.Member::higherCategoryLabel().' minimum')
                    ->state(fn (Member $record) => round((float) $record->savings_balance - $min60, 2))
                    ->money('PHP')->visibleFrom('md'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('has_mobile')->label('Mobile number')
                    ->placeholder('Any mobile number')
                    ->trueLabel('Has a mobile number')
                    ->falseLabel('No mobile number')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('contact_number')->where('contact_number', '<>', ''),
                        false: fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull('contact_number')->orWhere('contact_number', '')),
                    ),
                ...$this->memberFilters(),
            ])
            ->headerActions([$this->smsToAllAction()])
            ->actions([
                $this->smsAction(),
                Tables\Actions\Action::make('requestUpgrade')
                    ->label('Record request')
                    ->icon('heroicon-o-arrow-trending-up')
                    ->color('success')
                    ->visible(fn () => $this->canManage())
                    ->requiresConfirmation()
                    ->modalHeading(fn (Member $record) => 'Record a '.Member::higherCategoryLabel()." upgrade request for {$record->account_name}?")
                    ->modalDescription("Use this once the member has applied to upgrade. They move to the 90-day upgrades view and become eligible after {$wait} days.")
                    ->modalSubmitActionLabel('Record request')
                    ->action(function (Member $record) {
                        $record->requestCategoryUpgrade();

                        Notification::make()->title('Upgrade request recorded')
                            ->body('Eligible on '.$record->upgradeEligibleDate()->toFormattedDateString().'.')
                            ->success()->send();
                    }),
            ])
            ->bulkActions([$this->smsBulkAction()])
            ->defaultSort('savings_balance', 'desc')
            ->recordUrl(fn (Member $record) => MemberResource::getUrl('view', ['record' => $record]));
    }

    private function applyUpgrades(Collection $members): void
    {
        $applied = $members->filter(fn (Member $member) => $member->applyCategoryUpgradeIfEligible())->count();
        $skipped = $members->count() - $applied;

        Notification::make()
            ->title("{$applied} member(s) moved to ".Member::higherCategoryLabel())
            ->body($skipped ? "{$skipped} skipped — not yet eligible or savings below the ".Member::higherCategoryLabel().' maintaining balance.' : null)
            ->success()->send();
    }

    // ------------------------------------------------------------ SMS reminders

    /**
     * Text one member. The view's reminder is pre-filled and can be reworded before sending.
     */
    private function smsAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('sendSms')
            ->label('Send SMS')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->color('info')
            ->visible(fn () => $this->canSendSms())
            ->disabled(fn (Member|ReplenishmentNotice $record) => $this->mobileNumberOf($record) === null || $this->memberOf($record)?->sms_opt_out)
            ->tooltip(fn (Member|ReplenishmentNotice $record) => match (true) {
                (bool) $this->memberOf($record)?->sms_opt_out => 'The member asked not to receive SMS',
                $this->mobileNumberOf($record) === null => 'No mobile number on file',
                default => null,
            })
            ->modalHeading(fn (Member|ReplenishmentNotice $record) => 'Send SMS to '.$this->memberOf($record)?->account_name)
            ->modalSubmitActionLabel('Send SMS')
            ->fillForm(fn (Member|ReplenishmentNotice $record) => ['message' => app(MonitoringSms::class)->message($this->tab, $record)])
            ->form([
                Placeholder::make('to')
                    ->label('Mobile number')
                    ->content(fn (Member|ReplenishmentNotice $record) => $this->mobileNumberOf($record)),
                Textarea::make('message')
                    ->rows(5)
                    ->required()
                    ->maxLength(480)
                    ->helperText('About 160 characters per SMS; a longer message is sent as several parts.'),
            ])
            ->action(fn (Member|ReplenishmentNotice $record, array $data) => $this->sendSms([$record], $data['message']));
    }

    /**
     * Text the ticked rows — each member gets this view's reminder with their own name, dates and amounts.
     */
    private function smsBulkAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('sendSmsToSelected')
            ->label('Send SMS to selected')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->color('info')
            ->visible(fn () => $this->canSendSms())
            ->requiresConfirmation()
            ->modalHeading('Send SMS to the selected members?')
            ->modalDescription('Each member receives the "'.self::TABS[$this->tab][0].'" reminder with their own name, dates and amounts. Members without a mobile number are skipped.')
            ->modalSubmitActionLabel('Send SMS')
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => $this->sendSms($records));
    }

    /**
     * Text everyone currently listed (the view with its search and filters applied).
     */
    private function smsToAllAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('sendSmsToAll')
            ->label('Send SMS to all')
            ->icon('heroicon-o-megaphone')
            ->color('info')
            ->visible(fn () => $this->canSendSms())
            ->requiresConfirmation()
            ->modalHeading(fn () => 'Send SMS to all '.number_format($this->getFilteredTableQuery()->count()).' listed?')
            ->modalDescription('Everyone in this list (with the current search and filters) receives the "'.self::TABS[$this->tab][0].'" reminder with their own name, dates and amounts. Members without a mobile number are skipped.')
            ->modalSubmitActionLabel('Send SMS to all')
            ->action(fn () => $this->sendSms($this->getFilteredTableQuery()->get()));
    }

    private function memberOf(Member|ReplenishmentNotice $record): ?Member
    {
        return $record instanceof Member ? $record : $record->member;
    }

    private function mobileNumberOf(Member|ReplenishmentNotice $record): ?string
    {
        return SmsGateway::normalizeNumber($this->memberOf($record)?->contact_number);
    }

    /**
     * @param  iterable<Member|ReplenishmentNotice>  $records
     */
    private function sendSms(iterable $records, ?string $message = null): void
    {
        abort_unless($this->canSendSms(), 403);

        if (! app(SmsGateway::class)->isConfigured()) {
            Notification::make()
                ->title('SMS is not set up yet')
                ->body('Add the eTxtMo API key (ETXTMO_API_KEY) to the .env file, then try again.')
                ->danger()->send();

            return;
        }

        set_time_limit(0);

        $summary = app(MonitoringSms::class)->send($this->tab, $records, $message);

        $details = array_filter([
            $summary['no_number'] ? "{$summary['no_number']} skipped — no mobile number on file." : null,
            $summary['opted_out'] ? "{$summary['opted_out']} skipped — asked not to receive SMS." : null,
            $summary['not_applicable'] ? "{$summary['not_applicable']} skipped — nothing to remind in this view." : null,
            $summary['failed'] ? "{$summary['failed']} not sent: ".implode('; ', array_slice($summary['errors'], 0, 3)).($summary['failed'] > 3 ? '…' : '') : null,
        ]);

        Notification::make()
            ->title($summary['sent'] === 0 ? 'No SMS was sent' : "{$summary['sent']} SMS queued for sending")
            ->body($details ? implode(' ', $details) : null)
            ->status($summary['sent'] === 0 ? 'danger' : ($summary['failed'] ? 'warning' : 'success'))
            ->persistent($details !== [])
            ->send();
    }

    private function replenishmentTable(Table $table): Table
    {
        $warn = $this->policy()->int('replenishment_warning_days');

        return $table
            ->query(ReplenishmentNotice::query()->with('member.branch'))
            ->heading('Replenishment notices')
            ->description('Savings below the maintaining balance must be replenished within '.$this->policy()->int('replenishment_days').' days of notice (Policy II.4). Expired notices terminate '.Member::basicCategoryLabel().' participation or downgrade '.Member::higherCategoryLabel().' to '.Member::basicCategoryLabel().' (Policy VII).')
            ->columns([
                Tables\Columns\TextColumn::make('member.account_no')->label('Acct. Number')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('member.account_name')->label('Account Name')->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('member', fn (Builder $q) => $q->searchName($search))),
                Tables\Columns\TextColumn::make('member.branch.name')->label('Branch')->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('category')->badge()->formatStateUsing(fn (string $state) => Member::categoryLabel($state))->visibleFrom('md'),
                Tables\Columns\TextColumn::make('required_balance')->label('Required')->money('PHP')->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('member.savings_balance')->label('Current balance')->money('PHP'),
                Tables\Columns\TextColumn::make('shortfall')->label('Shortfall now')
                    ->state(fn (ReplenishmentNotice $record) => max(0, round((float) $record->required_balance - (float) $record->member?->savings_balance, 2)))
                    ->money('PHP')->color('danger')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('notice_date')->date()->sortable()->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('deadline')->date()->sortable()
                    ->description(fn (ReplenishmentNotice $record) => $record->status === 'open'
                        ? ($record->isOverdue() ? 'Overdue' : $record->daysRemaining().' day(s) left')
                        : null)
                    ->color(fn (ReplenishmentNotice $record) => $record->status !== 'open' ? null : ($record->isOverdue() ? 'danger' : ($record->daysRemaining() <= $warn ? 'warning' : null))),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => ReplenishmentNotice::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => ReplenishmentNotice::statusColor($state)),
            ])
            ->filters([
                // One dropdown: the deadline checks are narrower kinds of "Open".
                Tables\Filters\SelectFilter::make('status')
                    ->placeholder('All notices')
                    ->options([
                        'open' => 'Open',
                        'due_soon' => "Open, due within {$warn} days",
                        'overdue' => 'Open, overdue',
                        ...collect(ReplenishmentNotice::STATUSES)->except('open')->all(),
                    ])
                    ->default('open')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'due_soon' => $query->where('status', 'open')->whereBetween('deadline', [Carbon::today()->toDateString(), Carbon::today()->addDays($warn)->toDateString()]),
                        'overdue' => $query->where('status', 'open')->where('deadline', '<', Carbon::today()->toDateString()),
                        default => $query->where('status', $data['value']),
                    }),
                $this->branchFilter('member'),
            ])
            ->headerActions([$this->smsToAllAction()])
            ->bulkActions([$this->smsBulkAction()])
            ->actions([
                $this->smsAction(),
                Tables\Actions\Action::make('recheck')
                    ->label('Re-check now')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (ReplenishmentNotice $record) => $this->canManage() && $record->status === 'open')
                    ->action(function (ReplenishmentNotice $record) {
                        app(MemberStatusEngine::class)->evaluate($record->member);
                        $record->refresh();

                        Notification::make()->title('Notice is now: '.ReplenishmentNotice::STATUSES[$record->status])->send();
                    }),
                Tables\Actions\Action::make('cancel')
                    ->label('Cancel notice')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->visible(fn (ReplenishmentNotice $record) => $this->canManage() && $record->status === 'open')
                    ->form([Textarea::make('remarks')->label('Reason')->required()])
                    ->action(fn (ReplenishmentNotice $record, array $data) => $record->update([
                        'status' => 'cancelled',
                        'resolved_at' => Carbon::today(),
                        'resolved_by' => Auth::id(),
                        'remarks' => $data['remarks'],
                    ])),
            ])
            ->defaultSort('deadline')
            ->recordUrl(fn (ReplenishmentNotice $record) => $record->member ? MemberResource::getUrl('view', ['record' => $record->member]) : null);
    }

    private function dormancyTable(Table $table): Table
    {
        $months = $this->policy()->int('dormancy_termination_months');

        $inactiveSince = Carbon::today()->subMonthsNoOverflow($this->policy()->int('dormancy_months'));
        $threshold = $this->policy()->decimal('dormant_balance_threshold');

        // The two kinds of dormant account: no transaction for the inactivity period, or savings below the threshold.
        $inTransaction = fn (Builder $query) => $query->where(fn (Builder $query) => $query
            ->where('savings_account_status', 'dormant-txn')
            ->orWhereDate('last_activity_date', '<=', $inactiveSince));
        $inBalance = fn (Builder $query) => $query->where(fn (Builder $query) => $query
            ->where('savings_account_status', 'dormant-bal')
            ->orWhere('savings_balance', '<', $threshold));

        return $table
            ->query(Member::query()->with('branch')->participating()->where(fn (Builder $q) => $q
                ->where('status', 'dormant')
                ->orWhere('savings_account_status', 'like', 'dormant%')))
            ->heading('Dormant savings accounts')
            ->description('Dormant in transaction: no transaction for '.$this->policy()->int('dormancy_months').' months. Dormant in balance: savings below ₱'.number_format($threshold, 2)
                .". Either, for {$months} consecutive months, terminates participation (Policy VII.2).")
            ->columns([
                ...$this->memberColumns(),
                Tables\Columns\TextColumn::make('dormancy_type')->label('Dormant in')
                    ->state(fn (Member $record) => match (true) {
                        $record->savings_account_status === 'dormant-txn', (bool) $record->last_activity_date?->lte($inactiveSince) => 'Transaction',
                        $record->savings_account_status === 'dormant-bal', $record->savings_balance !== null && (float) $record->savings_balance < $threshold => 'Balance',
                        default => 'Transaction',
                    })
                    ->badge()
                    ->color('warning'),
                Tables\Columns\TextColumn::make('last_activity_date')->label('Last transaction')->date()->placeholder('Not recorded')->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('dormant_since')->date()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('termination_on')->label('Terminates on')
                    ->state(fn (Member $record) => $record->dormant_since?->copy()->addMonthsNoOverflow($months))
                    ->date()
                    ->description(fn (Member $record) => $record->dormant_since
                        ? max(0, (int) Carbon::today()->diffInDays($record->dormant_since->copy()->addMonthsNoOverflow($months), false)).' day(s) left'
                        : null)
                    ->color('danger'),
                Tables\Columns\TextColumn::make('savings_balance')->money('PHP')->visibleFrom('md'),
            ])
            ->filters([
                // One dropdown: the kind of dormancy, or the accounts close to termination.
                Tables\Filters\SelectFilter::make('dormancy_type')->label('Dormant in')->placeholder('All dormant accounts')
                    ->options([
                        'transaction' => 'Dormant in transaction',
                        'balance' => 'Dormant in balance',
                        'approaching' => 'Terminating within '.$this->policy()->int('dormancy_warning_days').' days',
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'transaction' => $inTransaction($query),
                        'balance' => $inBalance($query),
                        'approaching' => $query->whereIn('id', app(MonitoringService::class)->dormancyApproachingTermination($this->policy()->int('dormancy_warning_days'))->select('id')),
                        default => $query,
                    }),
                ...$this->memberFilters(),
            ])
            ->headerActions([$this->smsToAllAction()])
            ->actions([$this->smsAction()])
            ->bulkActions([$this->smsBulkAction()])
            ->defaultSort('dormant_since')
            ->recordUrl(fn (Member $record) => MemberResource::getUrl('view', ['record' => $record]));
    }
}
