<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Models\AuditLog;
use App\Services\Access\Permissions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

/**
 * Role & permission management (Administrator only). The Administrator role always has every permission.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Roles & Permissions';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return Auth::user()?->can('roles.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess() && $record->name !== Permissions::ADMIN;
    }

    public static function canDelete(Model $record): bool
    {
        return static::canAccess() && ! array_key_exists($record->name, Permissions::ROLE_LABELS) && $record->users()->doesntExist();
    }

    public static function form(Form $form): Form
    {
        $groups = collect(Permissions::ALL)->groupBy(fn ($label, $name) => ucfirst(explode('.', $name)[0]), preserveKeys: true);

        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(50)
                    ->regex('/^[a-z_]+$/')
                    ->helperText('Lowercase letters and underscores.')
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?Role $record) => $record && array_key_exists($record->name, Permissions::ROLE_LABELS)),
                Forms\Components\Hidden::make('guard_name')->default('web'),
                Forms\Components\Section::make('Permissions')
                    ->description('Changes are recorded in the audit log.')
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->schema($groups->map(fn ($permissions, $group) => Forms\Components\CheckboxList::make("permission_groups.{$group}")
                        ->label($group)
                        ->options($permissions->all())
                        ->bulkToggleable())->values()->all()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['users', 'permissions']))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->formatStateUsing(fn (string $state) => Permissions::ROLE_LABELS[$state] ?? $state),
                Tables\Columns\TextColumn::make('permissions_count')->label('Permissions')
                    ->formatStateUsing(fn (Role $record, $state) => $record->name === Permissions::ADMIN ? 'All' : $state),
                Tables\Columns\TextColumn::make('users_count')->label('Users'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    /**
     * Sync a role's permissions and record the change in the audit trail.
     *
     * @param  list<string>  $permissions
     */
    public static function syncAndAudit(Role $role, array $permissions): void
    {
        $before = $role->permissions()->pluck('name')->sort()->values()->all();
        $role->syncPermissions($permissions);
        $after = $role->permissions()->pluck('name')->sort()->values()->all();

        if ($before !== $after) {
            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'permissions_changed',
                'module' => 'Role',
                'auditable_type' => Role::class,
                'auditable_id' => $role->id,
                'old_values' => ['permissions' => $before],
                'new_values' => ['permissions' => $after],
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 250),
            ]);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
