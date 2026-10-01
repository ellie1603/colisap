<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Branch;
use App\Models\User;
use App\Services\Access\Permissions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Staff Accounts';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return Auth::user()?->can('users.manage') ?? false;
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
                    ->rule(Password::min(8)->letters()->numbers())
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
                        : 'Super Admin: everything incl. users, roles, branches, policy & audit. Admin: members, imports, claims, monitoring, reports. CRS: add/import/edit members and beneficiaries. Auditor: read-only.')
                    ->required(),
                Forms\Components\Select::make('branch_id')
                    ->label('Home branch')
                    ->options(fn () => Branch::options())
                    ->helperText('Pre-selected when this user adds members.')
                    ->searchable(),
                Forms\Components\Toggle::make('is_active')
                    ->label('Account active')
                    ->helperText('Deactivated accounts cannot sign in.')
                    ->disabled(fn (?User $record) => $record?->is(Auth::user()) ?? false)
                    ->default(true)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
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
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->modalDescription('Your own account is never deleted, so you cannot lock yourself out.')
                        ->using(fn (Collection $records) => $records
                            ->reject(fn (User $user) => $user->is(Auth::user()))
                            ->each->delete()),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
