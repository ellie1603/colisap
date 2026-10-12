<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApplicationResource\Pages;
use App\Models\Application;
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
 * Applications are filed here with the applicant's own details — no member record is needed first.
 * The member is created only when the Administrator approves the application.
 */
class ApplicationResource extends Resource
{
    protected static ?string $model = Application::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Members';

    protected static ?int $navigationSort = 3;

    private const POLICY_MESSAGE = 'Required by policy. An applicant who does not meet this cannot apply for COLISAP.';

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

        // A new applicant's details are typed in; a re-application only names the member by Acct. Number.
        $isNewApplicant = fn (Forms\Get $get): bool => $get('type') !== 'reapplication';

        // Identity can be corrected while an application is pending and has no member record behind it.
        $isLocked = fn (?Application $record): bool => $record !== null && ($record->status !== 'pending' || $record->member_id !== null);

        return $form
            ->schema([
                Forms\Components\Section::make('Applicant')
                    ->description('Enter the applicant\'s details. They are added to Members only when the application is approved.')
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->options(Application::TYPES)
                            ->default('new')
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->helperText('Re-application is for a member whose participation was terminated or withdrawn.'),
                        Forms\Components\TextInput::make('account_no')
                            ->label('Acct. Number')
                            ->required()
                            ->maxLength(30)
                            ->regex('/^[0-9A-Za-z\-]+$/')
                            ->disabled($isLocked)
                            ->dehydrateStateUsing(fn (?string $state) => $state === null ? null : trim($state))
                            ->helperText(fn (Forms\Get $get) => $isNewApplicant($get)
                                ? 'Savings account number — the applicant\'s unique ID. Leading zeros are kept.'
                                : 'The Acct. Number of the member who is re-applying.')
                            ->rule(fn (Forms\Get $get, string $operation): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $operation): void {
                                // No double applications: checked when one is filed, see Application::filingProblem().
                                if ($operation === 'create' && ($problem = static::filingProblemFor((string) $get('type'), (string) $value))) {
                                    $fail($problem);
                                }
                            }),
                        Forms\Components\Placeholder::make('member_display')
                            ->label('Member')
                            ->content(fn (?Application $record) => $record?->applicantName().' · '.($record?->applicantBranchName() ?? 'No branch'))
                            ->visible(fn (Forms\Get $get, ?Application $record) => $record?->member_id !== null && ! $isNewApplicant($get)),
                        Forms\Components\TextInput::make('last_name')
                            ->required($isNewApplicant)
                            ->visible($isNewApplicant)
                            ->disabled($isLocked)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('first_name')
                            ->required($isNewApplicant)
                            ->visible($isNewApplicant)
                            ->disabled($isLocked)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('middle_name')
                            ->visible($isNewApplicant)
                            ->disabled($isLocked)
                            ->maxLength(255),
                        Forms\Components\Select::make('branch_id')
                            ->label('Branch')
                            ->options(fn () => Branch::options())
                            ->default(fn () => Auth::user()?->branch_id)
                            ->searchable()
                            ->required($isNewApplicant)
                            ->visible($isNewApplicant)
                            ->disabled($isLocked),
                        Forms\Components\DatePicker::make('birthdate')
                            ->maxDate(now())
                            ->required($isNewApplicant)
                            ->visible($isNewApplicant)
                            ->disabled($isLocked)
                            ->live(onBlur: true)
                            ->helperText(function (?string $state) {
                                if (! $state) {
                                    return 'Needed to check the age limit in the policy.';
                                }

                                $age = Carbon::parse($state)->diff(Carbon::today());

                                return "Age {$age->y} yrs {$age->m} mos";
                            })
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($policy): void {
                                $age = $value ? Carbon::parse($value)->diff(Carbon::today()) : null;
                                $limit = $policy->int('max_entry_age_months');

                                if ($age && ($age->y * 12 + $age->m) > $limit) {
                                    $fail('Over the maximum entry age of '.intdiv($limit, 12).' years and '.($limit % 12).' months, so the applicant cannot apply for COLISAP.');
                                }
                            }),
                        Forms\Components\TextInput::make('contact_number')
                            ->label('Contact number')
                            ->tel()
                            ->maxLength(50)
                            ->visible($isNewApplicant)
                            ->disabled($isLocked)
                            ->helperText('Optional.'),
                        Forms\Components\TextInput::make('savings_balance')
                            ->label('Savings balance')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('₱')
                            ->required($isNewApplicant)
                            ->visible($isNewApplicant)
                            ->disabled($isLocked)
                            ->helperText(fn (Forms\Get $get) => 'Must be at least ₱'.number_format($policy->minimumBalanceFor($get('category')), 2).' for the '.Member::categoryLabel($get('category')).' category. Recorded as the member\'s opening balance on approval.')
                            ->rule(fn (Forms\Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $policy): void {
                                $minimum = $policy->minimumBalanceFor($get('category'));

                                if ($value !== null && $value !== '' && (float) $value < $minimum) {
                                    $fail('Below the ₱'.number_format($minimum, 2).' maintaining balance for the '.Member::categoryLabel($get('category')).' category, so the applicant cannot apply for COLISAP yet.');
                                }
                            }),
                    ]),
                Forms\Components\Section::make('Application')
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                    ->schema([
                        Forms\Components\DatePicker::make('application_date')->default(now())->maxDate(now())->required(),
                        Forms\Components\Select::make('category')
                            ->label('Benefit category')
                            ->options(Member::categoryOptions())
                            ->default(Member::BASIC_CATEGORY)
                            ->required()
                            ->live(),
                        Forms\Components\Placeholder::make('status_display')->label('Status')
                            ->content(fn (?Application $record) => Application::STATUSES[$record?->status ?? 'pending']),
                        Forms\Components\Textarea::make('remarks')->rows(2)->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Qualification checklist (Policy II, X)')
                    ->description('Every item must be met. An applicant who does not meet the policy cannot apply for COLISAP.')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        Forms\Components\Toggle::make('good_health_declared')
                            ->label('In good health; not hospitalized in the last 12 months; no terminal illness')
                            ->accepted(fn (string $operation) => $operation === 'create')
                            ->validationMessages(['accepted' => self::POLICY_MESSAGE]),
                        Forms\Components\Toggle::make('age_verified')
                            ->label('Not over 59 years and 6 months old (birth certificate for 55+)')
                            ->accepted(fn (string $operation) => $operation === 'create')
                            ->validationMessages(['accepted' => self::POLICY_MESSAGE]),
                        Forms\Components\Toggle::make('balance_verified')
                            ->label(fn () => 'Savings maintaining balance verified ('.Member::basicCategoryLabel().' ₱'.number_format($policy->decimal('min_balance_40k')).' / '.Member::higherCategoryLabel().' ₱'.number_format($policy->decimal('min_balance_60k')).')')
                            ->accepted(fn (string $operation) => $operation === 'create')
                            ->validationMessages(['accepted' => self::POLICY_MESSAGE]),
                        Forms\Components\Toggle::make('fee_paid')
                            ->label(fn () => 'Re-application fee of ₱'.number_format($policy->decimal('reapplication_fee'), 2).' paid')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'reapplication')
                            ->accepted(fn (string $operation) => $operation === 'create')
                            ->validationMessages(['accepted' => self::POLICY_MESSAGE]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['member.branch', 'branch']))
            ->columns([
                Tables\Columns\TextColumn::make('application_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('applicant_account_no')->label('Acct. Number')->fontFamily('mono')
                    ->state(fn (Application $record) => $record->applicantAccountNo()),
                Tables\Columns\TextColumn::make('applicant_name')->label('Applicant')->wrap()
                    ->state(fn (Application $record) => $record->applicantName())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query) => $query
                        ->where('applications.account_no', 'like', "%{$search}%")
                        ->orWhere('applications.last_name', 'like', "%{$search}%")
                        ->orWhere('applications.first_name', 'like', "%{$search}%")
                        ->orWhereHas('member', fn (Builder $query) => $query->searchName($search)))),
                Tables\Columns\TextColumn::make('type')->formatStateUsing(fn (string $state) => Application::TYPES[$state] ?? $state)->visibleFrom('md'),
                Tables\Columns\TextColumn::make('category')->formatStateUsing(fn (string $state) => Member::categoryLabel($state))->badge()->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('applicant_branch')->label('Branch')->visibleFrom('lg')
                    ->state(fn (Application $record) => $record->applicantBranchName()),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => Application::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => Application::statusColor($state)),
                Tables\Columns\TextColumn::make('approved_at')->label('Decided')->date()->visibleFrom('lg'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(Application::STATUSES),
                Tables\Filters\SelectFilter::make('type')->options(Application::TYPES),
                Tables\Filters\SelectFilter::make('branch')
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

        if ($problem === null && $type === 'reapplication' && Branch::restrictedId() !== null && ! Member::where('account_no', trim($accountNo))->exists()) {
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
        $isReapplication = ($data['type'] ?? 'new') === 'reapplication';
        $member = $isReapplication
            ? Branch::withoutRestriction(fn () => Member::where('account_no', $data['account_no'])->first())
            : null;

        return [
            ...$data,
            'status' => 'pending',
            'member_id' => $member?->id,
            'branch_id' => $member?->branch_id ?? ($data['branch_id'] ?? null),
            'previous_status' => $member?->status,
            'fee_amount' => $isReapplication ? app(PolicySettings::class)->decimal('reapplication_fee') : 0,
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
