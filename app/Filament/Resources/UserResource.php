<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\Access\Permissions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Staff Accounts';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 1;

    /**
     * Staff Accounts belong to the developer (super admin) account only; the coop's own Administrator does not see them.
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return ($user?->isDeveloper() ?? false) && $user->can('users.manage');
    }

    /**
     * @param  User  $record
     */
    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record) && $record->isManageableBy(Auth::user());
    }

    /**
     * @param  User  $record
     */
    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && $record->isManageableBy(Auth::user());
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->required(fn (string $context) => $context === 'create')
                    ->rule(Password::defaults())
                    ->revealable()
                    ->dehydrated(fn ($state) => filled($state))
                    ->maxLength(255)
                    ->helperText('Leave blank to keep the current password when editing.'),
                Forms\Components\Select::make('roles')
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => Permissions::ROLE_LABELS[$record->name] ?? $record->name)
                    ->multiple()
                    ->preload()
                    ->disabled(fn (?User $record) => $record?->is(Auth::user()) ?? false)
                    ->helperText(fn (?User $record) => $record?->is(Auth::user())
                        ? 'You cannot change your own roles.'
                        : 'Administrator: full access, including users, roles, branches, policy settings and the audit log. CRS Officer: registers and encodes members (new member, upgrade, re-application), and imports or exports the Excel masterlist. Branch Manager: views the dashboard, members, BMPC members and monitoring of their branch, and approves the re-applications CRS Officers send.')
                    ->live()
                    ->required(),
                Forms\Components\Select::make('branch_id')
                    ->label('Home branch')
                    ->options(fn () => Branch::options())
                    ->helperText('A CRS Officer or Branch Manager with a home branch sees only that branch\'s members, dashboard, monitoring and reports; a Branch Manager needs one, as it decides whose re-applications they approve. Leave blank to show every branch. Administrators always see every branch.')
                    // A Branch Manager approves the re-applications of their own branch, so they must have one.
                    ->required(fn (Forms\Get $get) => in_array(
                        Role::where('name', Permissions::BRANCH_MANAGER)->value('id'),
                        array_map('intval', (array) $get('roles')),
                        true,
                    ))
                    ->searchable(),
                Forms\Components\Toggle::make('is_active')
                    ->label('Account active')
                    ->helperText('Deactivated accounts cannot sign in.')
                    ->disabled(fn (?User $record) => $record?->is(Auth::user()) ?? false)
                    ->default(true)
                    ->required(),
                Forms\Components\Toggle::make('in_usage_report')
                    ->label('Count in the System Usage report')
                    ->helperText('Turn off for developer or support accounts, so the report sent to management counts only real staff.')
                    ->visible(fn () => Auth::user()?->isDeveloper() ?? false)
                    ->default(true),
                Forms\Components\Toggle::make('is_developer')
                    ->label('Developer account')
                    ->helperText('Can open developer-only pages such as System Usage. Only a developer account can change this.')
                    ->visible(fn () => Auth::user()?->isDeveloper() ?? false)
                    ->disabled(fn (?User $record) => $record?->is(Auth::user()) ?? false)
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Last sign-in from the sign-in log, in the same query, for the periodic access review.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['roles', 'branch'])->addSelect([
                'last_sign_in_at' => AuditLog::query()
                    // With only a few staff accounts MySQL otherwise picks the action index and reads every
                    // sign-in of every user (measured 190 ms → 10 ms on a full-sized audit log).
                    ->useIndex('audit_logs_user_action_created_index')
                    ->selectRaw('max(created_at)')
                    ->where('action', 'login')
                    ->whereColumn('audit_logs.user_id', 'users.id'),
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->description(fn (User $record) => $record->is_active && self::needsAccessReview($record)
                        ? 'Not used for '.self::ACCESS_REVIEW_DAYS.'+ days — review access'
                        : null),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Roles')
                    ->formatStateUsing(fn (string $state) => Permissions::ROLE_LABELS[$state] ?? $state)
                    ->badge(),
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->placeholder('All')
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('last_sign_in_at')
                    ->label('Last sign-in')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('Never')
                    ->color(fn (User $record) => $record->is_active && self::needsAccessReview($record) ? 'warning' : null)
                    ->sortable()
                    ->visibleFrom('md'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->checkIfRecordIsSelectableUsing(fn (User $record) => $record->isManageableBy(Auth::user()))
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->modalDescription('Your own account is never deleted, so you cannot lock yourself out.')
                        ->using(fn (Collection $records) => $records
                            ->reject(fn (User $user) => $user->is(Auth::user()) || ! $user->isManageableBy(Auth::user()))
                            ->each->delete()),
                ]),
            ]);
    }

    /**
     * Accounts unused this long are flagged in Staff Accounts so the Administrator reviews (deactivates) them.
     */
    public const ACCESS_REVIEW_DAYS = 90;

    public static function needsAccessReview(User $user): bool
    {
        $lastUsed = $user->last_sign_in_at ?? $user->created_at;

        return $lastUsed !== null && Carbon::parse($lastUsed)->lt(now()->subDays(self::ACCESS_REVIEW_DAYS));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
