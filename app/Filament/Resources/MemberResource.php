<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberResource\Pages;
use App\Filament\Resources\MemberResource\RelationManagers;
use App\Models\Branch;
use App\Models\Member;
use App\Services\Policy\PolicySettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Members';

    protected static ?string $navigationLabel = 'Members';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'account_name';

    private static function canEditColisapFields(): bool
    {
        return Auth::user()?->can('members.update_colisap') ?? false;
    }

    private static function policy(): PolicySettings
    {
        return app(PolicySettings::class);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identification')
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                    ->schema([
                        Forms\Components\TextInput::make('account_no')
                            ->label('Acct. Number')
                            ->helperText('Savings account number — the member\'s unique ID. Leading zeros are kept.')
                            ->required()
                            ->maxLength(30)
                            ->regex('/^[0-9A-Za-z\-]+$/')
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (string $operation) => $operation === 'edit' && ! static::canEditColisapFields())
                            ->dehydrateStateUsing(fn (?string $state) => $state === null ? null : trim($state)),
                        Forms\Components\Select::make('branch_id')
                            ->label('Branch')
                            ->options(fn () => Branch::options())
                            ->default(fn () => Auth::user()?->branch_id)
                            ->searchable()
                            ->required(),
                        Forms\Components\TextInput::make('account_name')
                            ->label('Account Name')
                            ->helperText('As shown in the masterlist. Leave blank to build it from the name.')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('last_name')->required()->maxLength(255),
                        Forms\Components\TextInput::make('first_name')->required()->maxLength(255),
                        Forms\Components\TextInput::make('middle_name')->maxLength(255),
                        Forms\Components\TextInput::make('suffix')->maxLength(20),
                        Forms\Components\DatePicker::make('birthdate')
                            ->maxDate(now())
                            ->live(onBlur: true)
                            ->helperText(function (?string $state) {
                                if (! $state) {
                                    return null;
                                }

                                $age = Carbon::parse($state)->diff(Carbon::today());
                                $maxMonths = static::policy()->int('max_entry_age_months');
                                $warning = ($age->y * 12 + $age->m) > $maxMonths ? ' — over the maximum entry age' : '';

                                return "Age {$age->y} yrs {$age->m} mos{$warning}";
                            }),
                        Forms\Components\Select::make('sex')->options(['male' => 'Male', 'female' => 'Female']),
                        Forms\Components\TextInput::make('contact_number')->tel()->maxLength(50),
                        Forms\Components\TextInput::make('email')->email()->maxLength(255),
                        Forms\Components\Textarea::make('address')->rows(2)->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('COLISAP')
                    ->description('Waiting / Active / Dormant are computed from policy. Only Admins can change status, category and segmentation directly.')
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                    ->schema([
                        Forms\Components\DatePicker::make('application_date')->maxDate(now()),
                        Forms\Components\DatePicker::make('approval_date')
                            ->label('Approval date')
                            ->maxDate(now())
                            ->live(onBlur: true)
                            ->helperText(fn (?string $state) => $state
                                ? 'Effective on '.Carbon::parse($state)->addDays(static::policy()->int('effectivity_days'))->toFormattedDateString().' ('.static::policy()->int('effectivity_days').'-day waiting period)'
                                : 'The waiting period starts on the approval date.'),
                        Forms\Components\Select::make('category')
                            ->label('Benefit category')
                            ->options(Member::CATEGORIES)
                            ->default('40000')
                            ->required()
                            ->disabled(fn (string $operation) => $operation === 'edit' && ! static::canEditColisapFields()),
                        Forms\Components\Select::make('segment')
                            ->label('Segmentation')
                            ->options(Member::SEGMENTS)
                            ->placeholder('Unassigned')
                            ->disabled(fn (string $operation) => $operation === 'edit' && ! static::canEditColisapFields()),
                        Forms\Components\Select::make('status')
                            ->options(Member::STATUSES)
                            ->default('waiting')
                            ->required()
                            ->live()
                            ->helperText('Deceased, Terminated and Withdrawn are kept as set; other statuses are recalculated on save.')
                            ->disabled(fn (string $operation) => ! static::canEditColisapFields())
                            ->visibleOn('edit'),
                        Forms\Components\DatePicker::make('date_deceased')
                            ->label('Date of death')
                            ->maxDate(now())
                            ->required(fn (Forms\Get $get) => $get('status') === 'deceased')
                            ->visible(fn (Forms\Get $get) => $get('status') === 'deceased'),
                        Forms\Components\TextInput::make('termination_reason')
                            ->visible(fn (Forms\Get $get) => $get('status') === 'terminated')
                            ->required(fn (Forms\Get $get) => $get('status') === 'terminated'),
                        Forms\Components\TextInput::make('savings_balance')
                            ->label('Opening savings balance')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('₱')
                            ->default(0)
                            ->visibleOn('create')
                            ->dehydrated(false)
                            ->helperText('Recorded as the first savings entry. Later changes come from savings transactions or the masterlist.'),
                        Forms\Components\Select::make('savings_account_status')
                            ->label('Savings account flag')
                            ->options(['dormant-txn' => 'Dormant (transaction)', 'dormant-bal' => 'Dormant (balance)'])
                            ->placeholder('Active')
                            ->disabled(fn () => ! static::canEditColisapFields())
                            ->visibleOn('edit'),
                        Forms\Components\Textarea::make('remarks')->rows(2)->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Tabs::make('Profile')
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs([
                        Infolists\Components\Tabs\Tab::make('Overview')
                            ->icon('heroicon-m-identification')
                            ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                            ->schema([
                                Infolists\Components\TextEntry::make('account_no')->label('Acct. Number')->copyable()->weight('bold'),
                                Infolists\Components\TextEntry::make('account_name')->label('Account Name'),
                                Infolists\Components\TextEntry::make('branch.name')->label('Branch')->placeholder('Not set'),
                                Infolists\Components\TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state) => Member::STATUSES[$state] ?? $state)
                                    ->color(fn (string $state) => Member::statusColor($state))
                                    ->icon(fn (string $state) => Member::statusIcon($state)),
                                Infolists\Components\TextEntry::make('category')->label('Benefit category')->badge()
                                    ->formatStateUsing(fn (Member $record) => Member::categoryLabel($record->category).' — ₱'.number_format($record->benefitAmount())),
                                Infolists\Components\TextEntry::make('segment')->label('Segmentation')->badge()->default('—')
                                    ->formatStateUsing(fn (Member $record) => Member::segmentLabel($record->segment))
                                    ->color(fn (Member $record) => $record->segment ? 'primary' : 'gray'),
                                Infolists\Components\TextEntry::make('savings_balance')->label('Savings balance')->money('PHP')
                                    ->color(fn (Member $record) => $record->meetsMinimumBalance() ? null : 'danger')
                                    ->helperText(fn (Member $record) => 'Minimum ₱'.number_format($record->minimumBalance(), 2)),
                                Infolists\Components\TextEntry::make('effectivity')->label('Effectivity date')
                                    ->state(fn (Member $record) => $record->effectivityDate()?->toFormattedDateString() ?? 'No approval date')
                                    ->helperText(fn (Member $record) => $record->status === 'waiting' && $record->daysUntilEffective() !== null ? $record->daysUntilEffective().' day(s) remaining' : null),
                                Infolists\Components\TextEntry::make('upgrade')->label('60K upgrade')
                                    ->state(fn (Member $record) => match ($record->upgradeStatus()) {
                                        'upgraded' => 'Already 60K',
                                        'eligible' => 'Eligible since '.$record->upgradeEligibleDate()->toFormattedDateString(),
                                        'pending' => 'Eligible on '.$record->upgradeEligibleDate()->toFormattedDateString(),
                                        default => 'No request',
                                    }),
                                Infolists\Components\TextEntry::make('dormancy')->label('Dormancy')
                                    ->state(fn (Member $record) => $record->dormant_since
                                        ? 'Dormant since '.$record->dormant_since->toFormattedDateString()
                                        : ($record->savings_account_status ?: 'Not dormant')),
                                Infolists\Components\TextEntry::make('openReplenishmentNotice.deadline')->label('Replenishment deadline')
                                    ->date()->placeholder('No open notice')->color('warning'),
                                Infolists\Components\TextEntry::make('eligibility')->label('Mortuary coverage')
                                    ->columnSpanFull()
                                    ->state(fn (Member $record) => $record->isEligible()
                                        ? new HtmlString('<span style="color: rgb(5 150 105)">✔ Covered — meets all COLISAP requirements</span>')
                                        : new HtmlString('✖ Not covered: '.e(implode('; ', $record->eligibilityIssues())))),
                            ]),
                        Infolists\Components\Tabs\Tab::make('Personal')
                            ->icon('heroicon-m-user')
                            ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                            ->schema([
                                Infolists\Components\TextEntry::make('last_name'),
                                Infolists\Components\TextEntry::make('first_name'),
                                Infolists\Components\TextEntry::make('middle_name')->placeholder('—'),
                                Infolists\Components\TextEntry::make('suffix')->placeholder('—'),
                                Infolists\Components\TextEntry::make('birthdate')->date()->placeholder('—')
                                    ->helperText(fn (Member $record) => $record->age() !== null ? $record->age().' years old' : null),
                                Infolists\Components\TextEntry::make('sex')->formatStateUsing(fn (?string $state) => ucfirst((string) $state))->placeholder('—'),
                                Infolists\Components\TextEntry::make('contact_number')->placeholder('—'),
                                Infolists\Components\TextEntry::make('email')->placeholder('—'),
                                Infolists\Components\TextEntry::make('address')->placeholder('—')->columnSpanFull(),
                            ]),
                        Infolists\Components\Tabs\Tab::make('COLISAP')
                            ->icon('heroicon-m-shield-check')
                            ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                            ->schema([
                                Infolists\Components\TextEntry::make('application_date')->date()->placeholder('—'),
                                Infolists\Components\TextEntry::make('approval_date')->date()->placeholder('—'),
                                Infolists\Components\TextEntry::make('activated_at')->label('Became active')->date()->placeholder('—'),
                                Infolists\Components\TextEntry::make('category_upgrade_requested_at')->label('Upgrade requested')->date()->placeholder('—'),
                                Infolists\Components\TextEntry::make('date_deceased')->label('Date of death')->date()->placeholder('—'),
                                Infolists\Components\TextEntry::make('terminated_at')->label('Terminated')->date()->placeholder('—')
                                    ->helperText(fn (Member $record) => $record->termination_reason),
                                Infolists\Components\TextEntry::make('withdrawn_at')->label('Withdrawn')->date()->placeholder('—')
                                    ->helperText(fn (Member $record) => $record->withdrawal_reason),
                                Infolists\Components\TextEntry::make('withdrawal_document')->label('Withdrawal letter')
                                    ->formatStateUsing(fn () => 'Download')
                                    ->url(fn (Member $record) => $record->withdrawal_document ? route('members.withdrawal-letter', $record) : null, true)
                                    ->placeholder('—'),
                                Infolists\Components\TextEntry::make('remarks')->placeholder('—')->columnSpanFull(),
                            ]),
                        Infolists\Components\Tabs\Tab::make('Savings')
                            ->icon('heroicon-m-banknotes')
                            ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                            ->schema([
                                Infolists\Components\TextEntry::make('savings_balance')->money('PHP'),
                                Infolists\Components\TextEntry::make('savings_balance_as_of')->label('Balance as of')->date()->placeholder('—'),
                                Infolists\Components\TextEntry::make('required')->label('Required balance')->state(fn (Member $record) => $record->minimumBalance())->money('PHP'),
                                Infolists\Components\TextEntry::make('shortfall')->state(fn (Member $record) => $record->balanceShortfall())->money('PHP')
                                    ->color(fn (Member $record) => $record->balanceShortfall() > 0 ? 'danger' : null),
                                Infolists\Components\TextEntry::make('savings_account_status')->label('Savings account flag')->placeholder('Active'),
                                Infolists\Components\TextEntry::make('last_activity_date')->label('Last recorded activity')->date()->placeholder('Not recorded'),
                                Infolists\Components\TextEntry::make('dormant_since')->date()->placeholder('—'),
                            ]),
                        Infolists\Components\Tabs\Tab::make('Source')
                            ->icon('heroicon-m-document-arrow-up')
                            ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                            ->schema([
                                Infolists\Components\TextEntry::make('importBatch.file_name')->label('Last import file')->placeholder('Entered manually'),
                                Infolists\Components\TextEntry::make('source_sheet')->label('Sheet')->placeholder('—'),
                                Infolists\Components\TextEntry::make('source_row')->label('Row')->placeholder('—'),
                                Infolists\Components\TextEntry::make('creator.name')->label('Created by')->placeholder('System'),
                                Infolists\Components\KeyValueEntry::make('source_values')->label('Original masterlist values')->columnSpanFull()
                                    ->keyLabel('Column')->valueLabel('Value'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('branch'))
            ->columns([
                Tables\Columns\TextColumn::make('account_no')
                    ->label('CIF Key')
                    ->tooltip('CIF Key = Acct. Number')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('account_name')
                    ->label('Account Name')
                    ->description(fn (Member $record) => $record->trashed() ? 'Archived' : null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->searchName($search))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('last_name', $direction)->orderBy('first_name', $direction))
                    ->wrap(),
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->sortable()
                    ->toggleable()
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('segment')
                    ->label('Segmentation')
                    ->badge()
                    ->formatStateUsing(fn (Member $record) => Member::segmentLabel($record->segment))
                    ->default('—')
                    ->color(fn (Member $record) => $record->segment ? 'primary' : 'gray')
                    ->toggleable()
                    ->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Member::categoryLabel($state))
                    ->color(fn (string $state) => $state === '60000' ? 'warning' : 'gray')
                    ->toggleable()
                    ->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('membership_date')
                    ->label('Application Date')
                    ->state(fn (Member $record) => $record->application_date ?? $record->approval_date)
                    ->date()
                    ->placeholder('—')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw('coalesce(application_date, approval_date) '.($direction === 'desc' ? 'desc' : 'asc')))
                    ->toggleable()
                    ->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Member::STATUSES[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => Member::statusColor($state))
                    ->icon(fn (string $state) => Member::statusIcon($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('savings_balance')
                    ->label('Saving Balance')
                    ->money('PHP')
                    ->sortable()
                    ->alignEnd()
                    ->color(fn (Member $record) => $record->meetsMinimumBalance() ? null : 'danger')
                    ->toggleable()
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('approval_date')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('effectivity_date')
                    ->label('Effectivity')
                    ->state(fn (Member $record) => $record->effectivityDate())
                    ->date()
                    ->description(fn (Member $record) => $record->status === 'waiting' && $record->daysUntilEffective() !== null ? $record->daysUntilEffective().' days left' : null)
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('approval_date', $direction))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_activity_date')
                    ->label('Last activity')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(Member::STATUSES)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(fn () => Branch::options())
                    ->multiple()
                    ->searchable(),
                Tables\Filters\SelectFilter::make('segment')
                    ->label('Segmentation')
                    ->options([...Member::SEGMENTS, 'none' => 'Unassigned'])
                    ->multiple()
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], function (Builder $query, array $values) {
                        $query->where(function (Builder $query) use ($values) {
                            $segments = array_values(array_diff($values, ['none']));

                            if ($segments !== []) {
                                $query->whereIn('segment', $segments);
                            }

                            if (in_array('none', $values, true)) {
                                $query->orWhereNull('segment');
                            }
                        });
                    })),
                Tables\Filters\SelectFilter::make('category')
                    ->label('Category')
                    ->options(Member::CATEGORIES),
                Tables\Filters\TernaryFilter::make('below_minimum')
                    ->label('Savings vs. minimum')
                    ->placeholder('All')
                    ->trueLabel('Below minimum')
                    ->falseLabel('Meets minimum')
                    ->queries(
                        true: fn (Builder $query) => $query->where(fn (Builder $q) => $q
                            ->where(fn (Builder $q) => $q->where('category', '60000')->where('savings_balance', '<', static::policy()->decimal('min_balance_60k')))
                            ->orWhere(fn (Builder $q) => $q->where('category', '<>', '60000')->where('savings_balance', '<', static::policy()->decimal('min_balance_40k')))),
                        false: fn (Builder $query) => $query->where(fn (Builder $q) => $q
                            ->where(fn (Builder $q) => $q->where('category', '60000')->where('savings_balance', '>=', static::policy()->decimal('min_balance_60k')))
                            ->orWhere(fn (Builder $q) => $q->where('category', '<>', '60000')->where('savings_balance', '>=', static::policy()->decimal('min_balance_40k')))),
                    ),
                Tables\Filters\TernaryFilter::make('dormancy_flag')
                    ->label('Savings dormancy flag')
                    ->placeholder('All')
                    ->trueLabel('Flagged dormant')
                    ->falseLabel('Not flagged')
                    ->queries(
                        true: fn (Builder $query) => $query->where('savings_account_status', 'like', 'dormant%'),
                        false: fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull('savings_account_status')->orWhere('savings_account_status', 'not like', 'dormant%')),
                    ),
                static::dateRangeFilter('approval_date', 'Approval date'),
                static::dateRangeFilter('application_date', 'Application date'),
                Tables\Filters\Filter::make('effectivity')
                    ->form([
                        Forms\Components\DatePicker::make('effective_from')->label('Effective from'),
                        Forms\Components\DatePicker::make('effective_until')->label('Effective until'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        $days = static::policy()->int('effectivity_days');

                        return $query
                            ->when($data['effective_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('approval_date', '>=', Carbon::parse($date)->subDays($days)))
                            ->when($data['effective_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('approval_date', '<=', Carbon::parse($date)->subDays($days)));
                    }),
                Tables\Filters\Filter::make('balance')
                    ->form([
                        Forms\Components\TextInput::make('balance_min')->label('Savings from (₱)')->numeric(),
                        Forms\Components\TextInput::make('balance_max')->label('Savings to (₱)')->numeric(),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when(filled($data['balance_min'] ?? null), fn (Builder $q) => $q->where('savings_balance', '>=', (float) $data['balance_min']))
                        ->when(filled($data['balance_max'] ?? null), fn (Builder $q) => $q->where('savings_balance', '<=', (float) $data['balance_max']))),
                Tables\Filters\Filter::make('age')
                    ->form([
                        Forms\Components\TextInput::make('age_min')->label('Age from')->numeric(),
                        Forms\Components\TextInput::make('age_max')->label('Age to')->numeric(),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when(filled($data['age_min'] ?? null), fn (Builder $q) => $q->whereDate('birthdate', '<=', Carbon::today()->subYears((int) $data['age_min'])))
                        ->when(filled($data['age_max'] ?? null), fn (Builder $q) => $q->whereDate('birthdate', '>', Carbon::today()->subYears((int) $data['age_max'] + 1)))),
                Tables\Filters\TrashedFilter::make()
                    ->label('Archived members'),
            ])
            ->filtersFormColumns(['sm' => 2, 'lg' => 3])
            ->persistFiltersInSession()
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton(),
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->label('Delete selected')
                    ->modalHeading('Delete the selected members?')
                    ->modalDescription('Deleted members are removed from lists, dashboards and reports. They are kept in the archive, so a mistake can be undone from the "Archived members" filter.')
                    ->modalSubmitActionLabel('Delete'),
                Tables\Actions\RestoreBulkAction::make()->label('Restore selected'),
            ])
            ->recordUrl(fn (Member $record) => static::getUrl('view', ['record' => $record]))
            ->defaultSort('account_name')
            ->paginated([25, 50, 100, 250])
            ->defaultPaginationPageOption(25)
            ->striped();
    }

    private static function dateRangeFilter(string $column, string $label): Tables\Filters\Filter
    {
        return Tables\Filters\Filter::make($column)
            ->form([
                Forms\Components\DatePicker::make("{$column}_from")->label("{$label} from"),
                Forms\Components\DatePicker::make("{$column}_until")->label("{$label} until"),
            ])
            ->query(fn (Builder $query, array $data) => $query
                ->when($data["{$column}_from"] ?? null, fn (Builder $q, $date) => $q->whereDate($column, '>=', $date))
                ->when($data["{$column}_until"] ?? null, fn (Builder $q, $date) => $q->whereDate($column, '<=', $date)));
    }

    /**
     * Include archived members so the "Archived members" filter and restore actions work.
     *
     * @return Builder<Member>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SavingsTransactionsRelationManager::class,
            RelationManagers\ApplicationsRelationManager::class,
            RelationManagers\ReplenishmentNoticesRelationManager::class,
            RelationManagers\HistoriesRelationManager::class,
            RelationManagers\AuditLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMembers::route('/'),
            'create' => Pages\CreateMember::route('/create'),
            'import' => Pages\ImportMembers::route('/import'),
            'view' => Pages\ViewMember::route('/{record}'),
            'edit' => Pages\EditMember::route('/{record}/edit'),
        ];
    }

    public static function getGlobalSearchResultTitle($record): string
    {
        return "{$record->account_no} — {$record->account_name}";
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['account_no', 'account_name', 'first_name', 'last_name'];
    }

    /**
     * Keep archived members out of the global search.
     *
     * @return Builder<Member>
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }
}
