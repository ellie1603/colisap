<?php

namespace App\Filament\Pages;

use App\Filament\Resources\MemberResource;
use App\Models\Branch;
use App\Models\Member;
use App\Models\ReplenishmentNotice;
use App\Services\Colisap\MemberStatusEngine;
use App\Services\Colisap\MonitoringService;
use App\Services\Policy\PolicyDefaults;
use App\Services\Policy\PolicySettings;
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
        'replenishment' => ['15-day replenishment', 'heroicon-m-bell-alert'],
        'dormancy' => ['Dormancy', 'heroicon-m-pause-circle'],
        'beneficiaries' => ['Beneficiary compliance', 'heroicon-m-user-group'],
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

    private function policy(): PolicySettings
    {
        return app(PolicySettings::class);
    }

    private function canManage(): bool
    {
        return Auth::user()?->can('monitoring.manage') ?? false;
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
            'replenishment' => ReplenishmentNotice::where('status', 'open')->count(),
            'dormancy' => Member::where('status', 'dormant')->count(),
            'beneficiaries' => $monitoring->withoutBeneficiaries()->count(),
        ];
    }

    public function table(Table $table): Table
    {
        return match ($this->tab) {
            'upgrades' => $this->upgradesTable($table),
            'replenishment' => $this->replenishmentTable($table),
            'dormancy' => $this->dormancyTable($table),
            'beneficiaries' => $this->beneficiariesTable($table),
            default => $this->effectivityTable($table),
        };
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
                Tables\Filters\SelectFilter::make('window')->label('Becoming effective within')
                    ->options(['30' => '30 days', '60' => '60 days', '90' => '90 days'])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $window) => $query
                        ->whereNotNull('approval_date')
                        ->where('approval_date', '<=', Carbon::today()->subDays($days)->addDays((int) $window)->toDateString()))),
                $this->branchFilter(),
            ])
            ->defaultSort('approval_date')
            ->recordUrl(fn (Member $record) => MemberResource::getUrl('view', ['record' => $record]));
    }

    private function upgradesTable(Table $table): Table
    {
        $wait = $this->policy()->int('upgrade_wait_days');
        $min60 = $this->policy()->decimal('min_balance_60k');

        return $table
            ->query(Member::query()->with('branch')->participating()->where('category', '40000')->whereNotNull('category_upgrade_requested_at'))
            ->heading('40K → 60K upgrade requests')
            ->description("Eligible {$wait} days after the request (Policy III.3). Moving to 60K also requires ₱".number_format($min60, 2).' savings.')
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
                $this->branchFilter(),
            ])
            ->actions([
                Tables\Actions\Action::make('apply')
                    ->label('Apply 60K')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Member $record) => $this->canManage() && $record->upgradeStatus() === 'eligible')
                    ->requiresConfirmation()
                    ->action(fn (Member $record) => $this->applyUpgrades(new Collection([$record]))),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('applyAll')
                    ->label('Apply 60K to selected')
                    ->icon('heroicon-o-check-badge')
                    ->visible(fn () => $this->canManage())
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => $this->applyUpgrades($records)),
            ])
            ->defaultSort('category_upgrade_requested_at')
            ->recordUrl(fn (Member $record) => MemberResource::getUrl('view', ['record' => $record]));
    }

    private function applyUpgrades(Collection $members): void
    {
        $applied = $members->filter(fn (Member $member) => $member->applyCategoryUpgradeIfEligible())->count();
        $skipped = $members->count() - $applied;

        Notification::make()
            ->title("{$applied} member(s) moved to 60K")
            ->body($skipped ? "{$skipped} skipped — not yet eligible or savings below the 60K maintaining balance." : null)
            ->success()->send();
    }

    private function replenishmentTable(Table $table): Table
    {
        $warn = $this->policy()->int('replenishment_warning_days');

        return $table
            ->query(ReplenishmentNotice::query()->with('member.branch'))
            ->heading('Replenishment notices')
            ->description('Savings below the maintaining balance must be replenished within '.$this->policy()->int('replenishment_days').' days of notice (Policy II.4). Expired notices terminate 40K participation or downgrade 60K to 40K (Policy VII).')
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
                Tables\Filters\SelectFilter::make('status')->options(ReplenishmentNotice::STATUSES)->default('open'),
                Tables\Filters\Filter::make('due_soon')->label("Deadline within {$warn} days")
                    ->query(fn (Builder $query) => $query->where('status', 'open')->whereBetween('deadline', [Carbon::today()->toDateString(), Carbon::today()->addDays($warn)->toDateString()])),
                Tables\Filters\Filter::make('overdue')->label('Overdue')
                    ->query(fn (Builder $query) => $query->where('status', 'open')->where('deadline', '<', Carbon::today()->toDateString())),
                $this->branchFilter('member'),
            ])
            ->actions([
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

        return $table
            ->query(Member::query()->with('branch')->participating()->where(fn (Builder $q) => $q
                ->where('status', 'dormant')
                ->orWhere('savings_account_status', 'like', 'dormant%')))
            ->heading('Dormant savings accounts')
            ->description("Dormant for {$months} consecutive months terminates participation (Policy VII.2). Activity source: ".(PolicyDefaults::SETTINGS['dormancy_activity_source']['options'][$this->policy()->get('dormancy_activity_source')] ?? ''))
            ->columns([
                ...$this->memberColumns(),
                Tables\Columns\TextColumn::make('savings_account_status')->label('Masterlist flag')->badge()->color('warning')->placeholder('—'),
                Tables\Columns\TextColumn::make('last_activity_date')->label('Last activity')->date()->placeholder('Not recorded')->visibleFrom('lg'),
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
                Tables\Filters\Filter::make('approaching')->label('Termination within '.$this->policy()->int('dormancy_warning_days').' days')
                    ->query(fn (Builder $query) => $query->whereIn('id', app(MonitoringService::class)->dormancyApproachingTermination($this->policy()->int('dormancy_warning_days'))->select('id'))),
                $this->branchFilter(),
            ])
            ->defaultSort('dormant_since')
            ->recordUrl(fn (Member $record) => MemberResource::getUrl('view', ['record' => $record]));
    }

    private function beneficiariesTable(Table $table): Table
    {
        return $table
            ->query(app(MonitoringService::class)->withoutBeneficiaries(Member::query()->with('branch')))
            ->heading('Beneficiary information incomplete')
            ->description('Participating members with no active beneficiary. Each member needs '.$this->policy()->int('min_beneficiaries').'–'.$this->policy()->int('max_beneficiaries').' beneficiaries.')
            ->columns([
                ...$this->memberColumns(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => Member::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => Member::statusColor($state)),
                Tables\Columns\TextColumn::make('contact_number')->placeholder('—')->visibleFrom('md'),
            ])
            ->filters([$this->branchFilter()])
            ->actions([
                Tables\Actions\Action::make('add')
                    ->label('Add beneficiaries')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn () => Auth::user()?->can('beneficiaries.manage') ?? false)
                    ->url(fn (Member $record) => MemberResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('account_name')
            ->recordUrl(fn (Member $record) => MemberResource::getUrl('view', ['record' => $record]));
    }
}
