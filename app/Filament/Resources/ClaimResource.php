<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClaimResource\Pages;
use App\Filament\Resources\ClaimResource\RelationManagers;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\Claim;
use App\Models\ClaimDeduction;
use App\Models\Member;
use App\Services\Colisap\ContributionService;
use App\Services\Policy\PolicySettings;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Throwable;

class ClaimResource extends Resource
{
    protected static ?string $model = Claim::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Mortuary';

    protected static ?string $navigationLabel = 'Mortuary Claims';

    protected static ?string $modelLabel = 'mortuary claim';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $open = Claim::whereIn('status', ['submitted', 'under_review', 'approved'])->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Claim')
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                    ->schema([
                        Forms\Components\Select::make('member_id')
                            ->label('Deceased member')
                            ->relationship('member', 'account_name', fn (Builder $query) => $query->whereIn('status', ['active', 'waiting', 'dormant', 'deceased']))
                            ->getOptionLabelFromRecordUsing(fn (Member $record) => "{$record->account_no} — {$record->account_name}")
                            ->searchable(['account_no', 'account_name', 'first_name', 'last_name'])
                            ->live()
                            ->disabledOn('edit')
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('beneficiary_id', null))
                            ->helperText('Filing a claim records the member as deceased.')
                            ->rule(fn (?Model $record) => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                                if (! $record && Claim::where('member_id', $value)->where('status', '<>', 'rejected')->exists()) {
                                    $fail('This member already has an open or settled claim.');
                                }
                            })
                            ->required()
                            ->columnSpan(['sm' => 2]),
                        Forms\Components\Select::make('beneficiary_id')
                            ->label('Claimant (beneficiary)')
                            ->options(fn (Forms\Get $get) => Beneficiary::query()->where('member_id', $get('member_id'))->where('is_active', true)->pluck('full_name', 'id'))
                            ->disabled(fn (Forms\Get $get) => blank($get('member_id')))
                            ->helperText(fn (Forms\Get $get) => filled($get('member_id')) && ! Beneficiary::where('member_id', $get('member_id'))->where('is_active', true)->exists()
                                ? "This member has no beneficiaries. Add them on the member's page first."
                                : null)
                            ->required(),
                        Forms\Components\DatePicker::make('date_of_death')->maxDate(now())->required(),
                        Forms\Components\DatePicker::make('date_filed')->default(now())->maxDate(now())->required(),
                        Forms\Components\Placeholder::make('benefit')
                            ->label('Gross benefit')
                            ->content(function (Forms\Get $get, ?Claim $record) {
                                if ($record) {
                                    return '₱'.number_format((float) $record->gross_benefit, 2).' ('.Member::categoryLabel($record->category).' category at filing)';
                                }

                                $member = Member::find($get('member_id'));

                                return $member
                                    ? '₱'.number_format(app(PolicySettings::class)->benefitFor($member->category), 2).' ('.Member::categoryLabel($member->category).' category)'
                                    : 'Select a member';
                            }),
                        Forms\Components\Textarea::make('remarks')->rows(2)->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Settlement requirements (Policy XII)')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        Forms\Components\Toggle::make('death_certificate_verified')->label('Death certificate received and verified'),
                        Forms\Components\Toggle::make('certificate_verified')->label('Duplicate approved COLISAP application or COLISAP certificate verified'),
                        Forms\Components\Textarea::make('eligibility_notes')->label('Verification notes')->rows(2)->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Deductions — outstanding loans and other cooperative obligations')
                    ->description('Deducted from the gross benefit; the remainder goes to the beneficiaries.')
                    ->schema([
                        Forms\Components\Repeater::make('deductions')
                            ->relationship()
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->addActionLabel('Add deduction')
                            ->columns(['default' => 1, 'sm' => 3])
                            ->schema([
                                Forms\Components\Select::make('type')->options(ClaimDeduction::TYPES)->required(),
                                Forms\Components\TextInput::make('description')->required()->maxLength(255),
                                Forms\Components\TextInput::make('amount')->numeric()->minValue(0.01)->prefix('₱')->required(),
                            ]),
                    ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Claim')
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                    ->schema([
                        Infolists\Components\TextEntry::make('claim_no')->weight('bold')->copyable(),
                        Infolists\Components\TextEntry::make('status')->badge()
                            ->formatStateUsing(fn (string $state) => Claim::STATUSES[$state] ?? $state)
                            ->color(fn (string $state) => Claim::statusColor($state)),
                        Infolists\Components\TextEntry::make('member.account_no')->label('Acct. Number')
                            ->url(fn (Claim $record) => MemberResource::getUrl('view', ['record' => $record->member])),
                        Infolists\Components\TextEntry::make('member.account_name')->label('Member'),
                        Infolists\Components\TextEntry::make('member.branch.name')->label('Branch'),
                        Infolists\Components\TextEntry::make('member.segment')->label('Segmentation')
                            ->formatStateUsing(fn (Claim $record) => Member::segmentLabel($record->member?->segment))->default('—'),
                        Infolists\Components\TextEntry::make('category')->formatStateUsing(fn (?string $state) => Member::categoryLabel($state)),
                        Infolists\Components\TextEntry::make('beneficiary.full_name')->label('Claimant'),
                        Infolists\Components\TextEntry::make('date_of_death')->date(),
                        Infolists\Components\TextEntry::make('date_filed')->date(),
                        Infolists\Components\TextEntry::make('approved_at')->label('Date approved')->dateTime()->placeholder('—'),
                        Infolists\Components\TextEntry::make('settled_at')->label('Date settled')->dateTime()->placeholder('—'),
                        Infolists\Components\TextEntry::make('reviewer.name')->label('Processed by')->placeholder('—'),
                        Infolists\Components\TextEntry::make('approver.name')->label('Approved by')->placeholder('—'),
                        Infolists\Components\TextEntry::make('rejection_reason')->placeholder('—')->visible(fn (Claim $record) => $record->status === 'rejected')->columnSpanFull(),
                    ]),
                Infolists\Components\Section::make('Benefit computation')
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                    ->schema([
                        Infolists\Components\TextEntry::make('gross_benefit')->money('PHP'),
                        Infolists\Components\TextEntry::make('outstanding_loan')->label('Less: outstanding loans')->money('PHP'),
                        Infolists\Components\TextEntry::make('other_obligations')->label('Less: other obligations')->money('PHP'),
                        Infolists\Components\TextEntry::make('net_benefit')->label('Net benefit')->money('PHP')->weight('bold')->color('success'),
                    ]),
                Infolists\Components\Section::make('Eligibility verification')
                    ->schema([
                        Infolists\Components\TextEntry::make('checks')
                            ->hiddenLabel()
                            ->state(function (Claim $record) {
                                $issues = $record->eligibilityIssues();

                                return $issues === []
                                    ? new HtmlString('✔ Member was covered at the date of death and all documents are verified.')
                                    : new HtmlString('<ul style="list-style: disc; padding-left: 1.25rem">'.collect($issues)->map(fn ($issue) => '<li>'.e($issue).'</li>')->implode('').'</ul>');
                            }),
                        Infolists\Components\TextEntry::make('eligibility_notes')->label('Notes')->placeholder('—'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['member.branch', 'beneficiary']))
            ->columns([
                Tables\Columns\TextColumn::make('claim_no')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('member.account_no')->label('Acct. Number')->fontFamily('mono')->searchable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('member.account_name')->label('Member')->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('member', fn (Builder $query) => $query->searchName($search))),
                Tables\Columns\TextColumn::make('member.branch.name')->label('Branch')->toggleable()->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('date_of_death')->date()->sortable()->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('date_filed')->date()->sortable()->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('category')->badge()->formatStateUsing(fn (?string $state) => Member::categoryLabel($state))->visibleFrom('md'),
                Tables\Columns\TextColumn::make('gross_benefit')->money('PHP')->toggleable()->visibleFrom('xl'),
                Tables\Columns\TextColumn::make('net_benefit')->money('PHP')->sortable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => Claim::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => Claim::statusColor($state)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(Claim::STATUSES)->multiple(),
                Tables\Filters\SelectFilter::make('category')->options(Member::CATEGORIES),
                Tables\Filters\SelectFilter::make('branch')
                    ->options(fn () => Branch::options())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $branch) => $query->whereHas('member', fn (Builder $q) => $q->where('branch_id', $branch)))),
                Tables\Filters\Filter::make('date_filed')
                    ->form([
                        Forms\Components\DatePicker::make('filed_from'),
                        Forms\Components\DatePicker::make('filed_until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['filed_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('date_filed', '>=', $date))
                        ->when($data['filed_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('date_filed', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton(),
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\ActionGroup::make(static::workflowActions()),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('60s');
    }

    /**
     * Claim workflow actions: review → approve/reject → settle, and contribution generation.
     *
     * Built for either table rows or the claim page header (both action classes share the same API).
     *
     * @param  class-string<Tables\Actions\Action|Action>  $action
     * @return list<Tables\Actions\Action|Action>
     */
    public static function workflowActions(string $action = Tables\Actions\Action::class): array
    {
        $can = fn (string $permission) => Auth::user()?->can($permission) ?? false;

        return [
            $action::make('review')
                ->label('Start review')
                ->icon('heroicon-o-magnifying-glass')
                ->color('warning')
                ->visible(fn (Claim $record) => $record->status === 'submitted' && $can('claims.manage'))
                ->requiresConfirmation()
                ->action(function (Claim $record) {
                    $record->markUnderReview(Auth::user());
                    Notification::make()->title('Claim moved to Under Review')->success()->send();
                }),
            $action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (Claim $record) => $record->status === 'under_review' && $can('claims.approve'))
                ->modalDescription(fn (Claim $record) => $record->eligibilityIssues() === []
                    ? 'Net benefit ₱'.number_format((float) $record->net_benefit, 2).' will be split among the beneficiaries by share.'
                    : 'Unresolved: '.implode('; ', $record->eligibilityIssues()))
                ->requiresConfirmation()
                ->action(function (Claim $record) {
                    if (! $record->death_certificate_verified || ! $record->certificate_verified) {
                        Notification::make()->title('Documents not verified')
                            ->body('Verify the death certificate and COLISAP application/certificate first (Policy XII).')
                            ->danger()->send();

                        return;
                    }

                    $record->approve(Auth::user());
                    Notification::make()->title('Claim approved')->success()->send();
                }),
            $action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Claim $record) => in_array($record->status, ['submitted', 'under_review'], true) && $can('claims.approve'))
                ->form([Forms\Components\Textarea::make('reason')->label('Rejection reason')->required()])
                ->requiresConfirmation()
                ->action(function (Claim $record, array $data) {
                    $record->reject(Auth::user(), $data['reason']);
                    Notification::make()->title('Claim rejected')->send();
                }),
            $action::make('settle')
                ->label('Settle')
                ->icon('heroicon-o-banknotes')
                ->color('info')
                ->visible(fn (Claim $record) => $record->status === 'approved' && $can('claims.settle'))
                ->form([Forms\Components\TextInput::make('reference')->label('Voucher / reference no.')->maxLength(255)])
                ->requiresConfirmation()
                ->action(function (Claim $record, array $data) {
                    $record->settle(Auth::user(), $data['reference'] ?? null);
                    Notification::make()->title('Claim settled')->success()->send();
                }),
            $action::make('contributions')
                ->label('Generate contributions')
                ->icon('heroicon-o-hand-raised')
                ->visible(fn (Claim $record) => in_array($record->status, ['approved', 'settled'], true) && $can('contributions.manage') && ! $record->contributions()->exists())
                ->modalDescription(function (Claim $record) {
                    $rates = app(ContributionService::class)->rates($record);

                    return 'Each of '.number_format($rates['participants']).' active participants contributes ₱'.number_format($rates['base'], 2)
                        .($rates['additional_60k'] > 0 ? ' (+₱'.number_format($rates['additional_60k'], 2).' for 60K members)' : '')
                        .($rates['equal_share'] ? ' under the equal-share rule' : '')
                        .'. Diamond/Gold coop shares are applied and member shares are deducted from savings.';
                })
                ->requiresConfirmation()
                ->action(function (Claim $record) {
                    try {
                        $summary = app(ContributionService::class)->generateForClaim($record);
                    } catch (Throwable $exception) {
                        Notification::make()->title('Contributions not generated')->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Contributions generated')
                        ->body(number_format($summary['participants']).' participants · members ₱'.number_format($summary['member_total'], 2).' · coop ₱'.number_format($summary['coop_total'], 2).' · '.$summary['notices'].' replenishment notice(s)')
                        ->success()->send();
                }),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DocumentsRelationManager::class,
            RelationManagers\PayoutsRelationManager::class,
            RelationManagers\ContributionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClaims::route('/'),
            'create' => Pages\CreateClaim::route('/create'),
            'view' => Pages\ViewClaim::route('/{record}'),
            'edit' => Pages\EditClaim::route('/{record}/edit'),
        ];
    }
}
