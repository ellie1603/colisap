<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApplicationResource\Pages;
use App\Filament\Tables\TableFilters;
use App\Models\Application;
use App\Models\BmpcMember;
use App\Models\Branch;
use App\Models\Member;
use App\Services\Policy\PolicySettings;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Member transactions — the CRS Officer's module: add a new or an already-active member, upgrade a member to the
 * higher category, or re-apply a returning member. Nothing changes in Members until the Administrator approves.
 * (Stored as "applications".)
 */
class ApplicationResource extends Resource
{
    protected static ?string $model = Application::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Members';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Member Transactions';

    protected static ?string $modelLabel = 'member transaction';

    protected static ?string $pluralModelLabel = 'member transactions';

    public static function getNavigationBadge(): ?string
    {
        $pending = Application::where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        $policy = app(PolicySettings::class);

        // New and active members are typed in; an upgrade or a re-application names an existing member by Acct. Number.
        $isNewPerson = fn (Forms\Get $get): bool => in_array($get('type'), Application::FOR_NEW_PEOPLE, true);
        $isNewMember = fn (Forms\Get $get): bool => $get('type') === 'new';

        // Identity can be corrected while a request is pending and has no member record behind it.
        $isLocked = fn (?Application $record): bool => $record !== null && ($record->status !== 'pending' || $record->member_id !== null);

        return $form
            ->schema([
                Forms\Components\Section::make('Transaction')
                    ->description('Choose what you are filing. New members are added to Members, and upgrades take effect, when the Administrator approves; re-applications go to the Branch Manager of the member\'s branch.')
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->label('Type of transaction')
                            ->options(fn (?Application $record) => $record ? Application::typeOptions() : Application::filingTypeOptions())
                            ->in(fn (?Application $record) => array_keys($record ? Application::typeOptions() : Application::filingTypeOptions()))
                            ->default('new')
                            ->selectablePlaceholder(false)
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->helperText(fn (Forms\Get $get) => match ($get('type')) {
                                'active' => 'Someone already enrolled in COLISAP who is not in this system yet. They are added with their original approval date.',
                                'upgrade' => 'An active '.Member::basicCategoryLabel().' member moving to the '.Member::higherCategoryLabel().' category ('.$policy->int('upgrade_wait_days').'-day waiting period).',
                                'reapplication' => 'A member whose participation was terminated or withdrawn and who is joining again.',
                                default => 'Someone joining COLISAP for the first time.',
                            }),
                        Forms\Components\Select::make('bmpc_member_id')
                            ->label('Find BMPC member')
                            ->placeholder('Type a name or Acct. Number')
                            ->helperText('Pick the person to fill in their details from BMPC Members. Not on the list? Type the details below yourself.')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => BmpcMember::query()
                                ->with('branch')
                                ->searchName($search)
                                ->orderBy('last_name')
                                ->orderBy('first_name')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (BmpcMember $bmpcMember) => [$bmpcMember->getKey() => $bmpcMember->lookupLabel()])
                                ->all())
                            ->getOptionLabelUsing(fn (mixed $value): ?string => BmpcMember::query()->with('branch')->find($value)?->lookupLabel())
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Forms\Set $set): void {
                                foreach (BmpcMember::query()->find($state)?->transactionDetails() ?? [] as $field => $value) {
                                    $set($field, $value);
                                }
                            })
                            ->dehydrated(false)
                            ->visible(fn (Forms\Get $get, string $operation): bool => $operation === 'create' && $isNewPerson($get) && (Auth::user()?->can('bmpc_members.view') ?? false))
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('account_no')
                            ->label('Acct. Number')
                            ->required()
                            ->maxLength(30)
                            ->regex('/^[0-9A-Za-z\-]+$/')
                            ->disabled($isLocked)
                            ->dehydrateStateUsing(fn (?string $state) => $state === null ? null : trim($state))
                            ->helperText(fn (Forms\Get $get) => $isNewPerson($get)
                                ? 'Savings account number — the member\'s unique ID. Leading zeros are kept.'
                                : 'The Acct. Number of the member.')
                            ->rule(fn (Forms\Get $get, string $operation): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $operation): void {
                                // No double requests: checked when one is filed, see Application::filingProblem().
                                if ($operation === 'create' && ($problem = static::filingProblemFor((string) $get('type'), (string) $value))) {
                                    $fail($problem);
                                }
                            }),
                        Forms\Components\Placeholder::make('member_display')
                            ->label('Member')
                            ->content(fn (?Application $record) => $record?->applicantName().' · '.($record?->applicantBranchName() ?? 'No branch'))
                            ->visible(fn (Forms\Get $get, ?Application $record) => $record?->member_id !== null && ! $isNewPerson($get)),
                        Forms\Components\TextInput::make('last_name')
                            ->required($isNewPerson)
                            ->visible($isNewPerson)
                            ->disabled($isLocked)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('first_name')
                            ->required($isNewPerson)
                            ->visible($isNewPerson)
                            ->disabled($isLocked)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('middle_name')
                            ->visible($isNewPerson)
                            ->disabled($isLocked)
                            ->maxLength(255),
                        Forms\Components\Select::make('branch_id')
                            ->label('Branch')
                            ->options(fn () => Branch::options())
                            ->default(fn () => Auth::user()?->branch_id)
                            ->searchable()
                            ->required($isNewPerson)
                            ->visible($isNewPerson)
                            ->disabled($isLocked),
                        Forms\Components\DatePicker::make('birthdate')
                            ->maxDate(now())
                            ->required($isNewPerson)
                            ->visible($isNewPerson)
                            ->disabled($isLocked)
                            ->live(onBlur: true)
                            ->helperText(function (?string $state) {
                                if (! $state) {
                                    return 'Needed to check the age limit in the policy.';
                                }

                                $age = Carbon::parse($state)->diff(Carbon::today());

                                return "Age {$age->y} yrs {$age->m} mos";
                            })
                            // The entry age applies when someone joins; an active member joined in the past.
                            ->rule(fn (Forms\Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $policy, $isNewMember): void {
                                $age = $value ? Carbon::parse($value)->diff(Carbon::today()) : null;
                                $limit = $policy->int('max_entry_age_months');

                                if ($isNewMember($get) && $age && ($age->y * 12 + $age->m) > $limit) {
                                    $fail('Over the maximum entry age of '.intdiv($limit, 12).' years and '.($limit % 12).' months, so the applicant cannot apply for COLISAP.');
                                }
                            }),
                        Forms\Components\TextInput::make('contact_number')
                            ->label('Contact number')
                            ->tel()
                            ->maxLength(50)
                            ->visible($isNewPerson)
                            ->disabled($isLocked)
                            ->helperText('Optional.'),
                        Forms\Components\TextInput::make('savings_balance')
                            ->label('Savings balance')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('₱')
                            ->required($isNewPerson)
                            ->visible($isNewPerson)
                            ->disabled($isLocked)
                            ->helperText(fn (Forms\Get $get) => $isNewMember($get)
                                ? 'Must be at least ₱'.number_format($policy->minimumBalanceFor($get('category')), 2).' for the '.Member::categoryLabel($get('category')).' category. Recorded as the member\'s opening balance on approval.'
                                : 'Their savings today. If it is below the maintaining balance, the usual replenishment notice follows.')
                            ->rule(fn (Forms\Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $policy, $isNewMember): void {
                                $minimum = $policy->minimumBalanceFor($get('category'));

                                if ($isNewMember($get) && $value !== null && $value !== '' && (float) $value < $minimum) {
                                    $fail('Below the ₱'.number_format($minimum, 2).' maintaining balance for the '.Member::categoryLabel($get('category')).' category, so the applicant cannot apply for COLISAP yet.');
                                }
                            }),
                    ]),
                Forms\Components\Section::make('Details')
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                    ->schema([
                        Forms\Components\DatePicker::make('application_date')
                            ->label('Transaction date')
                            ->helperText(fn (Forms\Get $get) => $get('type') === 'upgrade' ? 'The upgrade request date: the upgrade waiting period counts from it.' : null)
                            ->default(now())->maxDate(now())->required(),
                        Forms\Components\DatePicker::make('member_since')
                            ->label('Original approval date')
                            ->helperText('When they were approved in COLISAP. Their waiting period counts from this date.')
                            ->maxDate(now())
                            ->required(fn (Forms\Get $get) => $get('type') === 'active')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'active')
                            ->disabled($isLocked),
                        Forms\Components\Select::make('category')
                            ->label('Benefit category')
                            ->options(Member::categoryOptions())
                            ->default(Member::BASIC_CATEGORY)
                            ->required(fn (Forms\Get $get) => $get('type') !== 'upgrade')
                            ->visible(fn (Forms\Get $get) => $get('type') !== 'upgrade')
                            ->live(),
                        Forms\Components\TextInput::make('or_number')
                            ->label('OR number')
                            ->helperText(fn () => 'Official receipt number of the ₱'.number_format($policy->decimal('reapplication_fee'), 2).' re-application fee.')
                            ->required(fn (Forms\Get $get) => $get('type') === 'reapplication')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'reapplication')
                            ->maxLength(50)
                            ->regex('/^[0-9A-Za-z\-\/ ]+$/'),
                        Forms\Components\Placeholder::make('status_display')->label('Status')
                            ->content(fn (?Application $record) => Application::STATUSES[$record?->status ?? 'pending']),
                        Forms\Components\Textarea::make('remarks')->rows(2)->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['member.branch', 'branch']))
            ->searchPlaceholder('Search name or Acct. Number')
            ->columns([
                Tables\Columns\TextColumn::make('application_date')->label('Transaction date')->date()->sortable(),
                Tables\Columns\TextColumn::make('applicant_account_no')->label('Acct. Number')->fontFamily('mono')
                    ->state(fn (Application $record) => $record->applicantAccountNo()),
                Tables\Columns\TextColumn::make('applicant_name')->label('Applicant')->wrap()
                    ->state(fn (Application $record) => $record->applicantName())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query) => $query
                        ->where('applications.account_no', 'like', "%{$search}%")
                        ->orWhere('applications.last_name', 'like', "%{$search}%")
                        ->orWhere('applications.first_name', 'like', "%{$search}%")
                        ->orWhereHas('member', fn (Builder $query) => $query->searchName($search)))),
                Tables\Columns\TextColumn::make('type')->formatStateUsing(fn (string $state) => Application::typeOptions()[$state] ?? $state)->visibleFrom('md'),
                Tables\Columns\TextColumn::make('or_number')->label('OR No.')->placeholder('—')->fontFamily('mono')->visibleFrom('xl'),
                Tables\Columns\TextColumn::make('category')->formatStateUsing(fn (string $state) => Member::categoryLabel($state))->badge()->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('applicant_branch_code')->label('Branch code')->fontFamily('mono')->placeholder('—')->visibleFrom('lg')
                    ->state(fn (Application $record) => $record->applicantBranchCode()),
                Tables\Columns\TextColumn::make('applicant_branch')->label('Branch name')->placeholder('—')->visibleFrom('md')
                    ->state(fn (Application $record) => $record->applicantBranchName()),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => Application::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => Application::statusColor($state)),
                Tables\Columns\TextColumn::make('approved_at')->label('Decided')->date()->visibleFrom('lg'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Type of transaction')->placeholder('All types')->options(Application::typeOptions()),
                Tables\Filters\SelectFilter::make('status')->placeholder('All statuses')->options(Application::STATUSES),
                TableFilters::transactionDate('application_date'),
                Tables\Filters\SelectFilter::make('branch')
                    ->placeholder('All branches')
                    ->visible(fn () => Branch::restrictedId() === null)
                    ->options(fn () => Branch::options())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $branch) => $query->where(fn (Builder $query) => $query
                        ->where(fn (Builder $query) => $query->whereNull('applications.member_id')->where('applications.branch_id', $branch))
                        ->orWhereHas('member', fn (Builder $query) => $query->where('branch_id', $branch))))),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Open'),
                Tables\Actions\ViewAction::make()
                    ->hidden(fn (Application $record) => static::canEdit($record))
                    ->mutateRecordDataUsing(fn (array $data, Application $record) => static::withMemberDetails($record, $data)),
            ])
            ->defaultSort('application_date', 'desc');
    }

    /**
     * Why this application cannot be filed, or null when it can — the no-double-application rules, plus
     * staff limited to one branch only re-file for their own branch's members.
     */
    public static function filingProblemFor(string $type, string $accountNo): ?string
    {
        $problem = Application::filingProblem($type, $accountNo);

        if ($problem === null && in_array($type, ['reapplication', 'upgrade'], true) && Branch::restrictedId() !== null && ! Member::where('account_no', trim($accountNo))->exists()) {
            return 'This member belongs to another branch.';
        }

        return $problem;
    }

    /**
     * A new application starts as pending. A re-application is linked to the member it names and carries
     * their current status and the re-application fee.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withCreationDefaults(array $data): array
    {
        $type = $data['type'] ?? 'new';
        $isReapplication = $type === 'reapplication';
        $member = ! in_array($type, Application::FOR_NEW_PEOPLE, true)
            ? Branch::withoutRestriction(fn () => Member::where('account_no', $data['account_no'])->first())
            : null;

        return [
            ...$data,
            'status' => 'pending',
            'member_id' => $member?->id,
            'branch_id' => $member?->branch_id ?? ($data['branch_id'] ?? null),
            'previous_status' => $member?->status,
            'fee_amount' => $isReapplication ? app(PolicySettings::class)->decimal('reapplication_fee') : 0,
            'fee_paid' => $isReapplication && filled($data['or_number'] ?? null),
            // An upgrade request is always for the higher category; the member's current category stays until it is applied.
            'category' => $type === 'upgrade' ? Member::HIGHER_CATEGORY : ($data['category'] ?? Member::BASIC_CATEGORY),
        ];
    }

    /**
     * Form values for an application that already has a member record: the member is the source of truth,
     * and applications recorded by an Excel import never carried their own copy of these details.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withMemberDetails(Application $application, array $data): array
    {
        $member = $application->member;

        if ($member === null) {
            return $data;
        }

        return [
            ...$data,
            'account_no' => $member->account_no,
            'last_name' => $member->last_name,
            'first_name' => $member->first_name,
            'middle_name' => $member->middle_name,
            'branch_id' => $member->branch_id,
            'birthdate' => $member->birthdate?->toDateString(),
            'contact_number' => $member->contact_number,
            'savings_balance' => $data['savings_balance'] ?? $member->savings_balance,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApplications::route('/'),
            'edit' => Pages\EditApplication::route('/{record}/edit'),
        ];
    }
}
