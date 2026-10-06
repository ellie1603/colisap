<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApplicationResource\Pages;
use App\Models\Application;
use App\Models\Branch;
use App\Models\Member;
use App\Services\Policy\PolicySettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ApplicationResource extends Resource
{
    protected static ?string $model = Application::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Members';

    protected static ?int $navigationSort = 3;

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

        return $form
            ->schema([
                Forms\Components\Section::make('Application')
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                    ->schema([
                        Forms\Components\Select::make('member_id')
                            ->label('Member')
                            ->relationship('member', 'account_name')
                            ->getOptionLabelFromRecordUsing(fn (Member $record) => "{$record->account_no} — {$record->account_name}")
                            ->searchable(['account_no', 'account_name', 'first_name', 'last_name'])
                            ->helperText('Add the member first (Members → Add member) if they are not in the system yet.')
                            ->disabledOn('edit')
                            ->required()
                            ->columnSpan(['sm' => 2]),
                        Forms\Components\Select::make('type')->options(Application::TYPES)->default('new')->required()->disabledOn('edit')
                            ->live(),
                        Forms\Components\DatePicker::make('application_date')->default(now())->maxDate(now())->required(),
                        Forms\Components\Select::make('category')->label('Benefit category')->options(Member::CATEGORIES)->default('40000')->required(),
                        Forms\Components\Placeholder::make('status_display')->label('Status')
                            ->content(fn (?Application $record) => Application::STATUSES[$record?->status ?? 'pending']),
                        Forms\Components\Textarea::make('remarks')->rows(2)->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Qualification checklist (Policy II, X)')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        Forms\Components\Toggle::make('good_health_declared')
                            ->label('In good health; not hospitalized in the last 12 months; no terminal illness'),
                        Forms\Components\Toggle::make('age_verified')
                            ->label('Not over 59 years and 6 months old (birth certificate for 55+)'),
                        Forms\Components\Toggle::make('balance_verified')
                            ->label(fn () => 'Savings maintaining balance verified (40K ₱'.number_format($policy->decimal('min_balance_40k')).' / 60K ₱'.number_format($policy->decimal('min_balance_60k')).')'),
                        Forms\Components\Toggle::make('fee_paid')
                            ->label(fn () => 'Re-application fee of ₱'.number_format($policy->decimal('reapplication_fee'), 2).' paid')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'reapplication'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('member.branch'))
            ->columns([
                Tables\Columns\TextColumn::make('application_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('member.account_no')->label('Acct. Number')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('member.account_name')->label('Member')->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('member', fn (Builder $query) => $query->searchName($search))),
                Tables\Columns\TextColumn::make('type')->formatStateUsing(fn (string $state) => Application::TYPES[$state] ?? $state)->visibleFrom('md'),
                Tables\Columns\TextColumn::make('category')->formatStateUsing(fn (string $state) => Member::categoryLabel($state))->badge()->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('member.branch.name')->label('Branch')->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => Application::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => Application::statusColor($state)),
                Tables\Columns\TextColumn::make('approved_at')->label('Decided')->date()->toggleable()->visibleFrom('lg'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(Application::STATUSES),
                Tables\Filters\SelectFilter::make('type')->options(Application::TYPES),
                Tables\Filters\SelectFilter::make('branch')
                    ->options(fn () => Branch::options())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $branch) => $query->whereHas('member', fn (Builder $q) => $q->where('branch_id', $branch)))),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Open'),
                Tables\Actions\ViewAction::make()->hidden(fn (Application $record) => static::canEdit($record)),
            ])
            ->defaultSort('application_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApplications::route('/'),
            'create' => Pages\CreateApplication::route('/create'),
            'edit' => Pages\EditApplication::route('/{record}/edit'),
        ];
    }
}
