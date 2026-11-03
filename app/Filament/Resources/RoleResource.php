<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Models\AuditLog;
use App\Services\Access\Permissions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\MaxWidth;
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

    /**
     * The permission picker's sections, in the sidebar's order, each with the sidebar's icon. A permission belongs to
     * the section that lists its prefix ("members.view" → Members).
     *
     * @var array<string, array{label: string, description: string, icon: string, prefixes: list<string>}>
     */
    public const PERMISSION_GROUPS = [
        'Members' => ['label' => 'Members', 'description' => 'COLISAP participant records, status, imports and exports.', 'icon' => 'heroicon-o-users', 'prefixes' => ['members']],
        'Bmpc' => ['label' => 'BMPC Members', 'description' => 'The whole cooperative member list.', 'icon' => 'heroicon-o-user-group', 'prefixes' => ['bmpc_members']],
        'Transactions' => ['label' => 'Member Transactions', 'description' => 'Filings and their approval.', 'icon' => 'heroicon-o-clipboard-document-check', 'prefixes' => ['applications']],
        'Savings' => ['label' => 'Savings', 'description' => 'Savings balances and transactions.', 'icon' => 'heroicon-o-banknotes', 'prefixes' => ['savings']],
        'Monitoring' => ['label' => 'Monitoring', 'description' => 'Alerts, notices, upgrades and SMS reminders.', 'icon' => 'heroicon-o-eye', 'prefixes' => ['monitoring']],
        'Reports' => ['label' => 'Reports & Imports', 'description' => 'Reports, import history and branch import approvals.', 'icon' => 'heroicon-o-document-chart-bar', 'prefixes' => ['reports', 'imports']],
        'Administration' => ['label' => 'Administration', 'description' => 'Branches, staff, roles, policy, audit logs and the monitoring API.', 'icon' => 'heroicon-o-cog-6-tooth', 'prefixes' => ['branches', 'users', 'roles', 'policy', 'audit', 'monitoring_api']],
    ];

    public static function permissionGroup(string $permission): string
    {
        $prefix = explode('.', $permission)[0];

        foreach (self::PERMISSION_GROUPS as $key => $group) {
            if (in_array($prefix, $group['prefixes'], true)) {
                return $key;
            }
        }

        return 'Administration';
    }

    /**
     * A role's permissions split into the picker's sections, as the form holds them.
     *
     * @return array<string, list<string>>
     */
    public static function permissionGroupsOf(Role $role): array
    {
        return $role->permissions->pluck('name')
            ->groupBy(fn (string $name) => static::permissionGroup($name))
            ->map->values()->all();
    }

    public static function form(Form $form): Form
    {
        $permissions = collect(Permissions::ALL)->groupBy(fn (string $label, string $name) => static::permissionGroup($name), preserveKeys: true);

        return $form
            ->columns(1)
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Role name')
                    ->placeholder('e.g. teller')
                    ->prefixIcon('heroicon-o-key')
                    ->required()
                    ->maxLength(50)
                    ->regex('/^[a-z_]+$/')
                    ->helperText('Lowercase letters and underscores.')
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?Role $record) => $record && array_key_exists($record->name, Permissions::ROLE_LABELS))
                    ->extraFieldWrapperAttributes(['class' => 'colisap-role-name']),
                Forms\Components\Hidden::make('guard_name')->default('web'),
                Forms\Components\Placeholder::make('permissions_heading')
                    ->label('Permissions')
                    ->content('Tick what this role may do. Changes are recorded in the audit log.')
                    ->extraAttributes(['class' => 'colisap-perm-heading']),
                // One row per section: its name and description on the left, its permissions as tick tiles on the right.
                ...collect(self::PERMISSION_GROUPS)->map(fn (array $group, string $key) => Forms\Components\Section::make($group['label'])
                    ->description($group['description'])
                    ->icon($group['icon'])
                    ->aside()
                    ->extraAttributes(['class' => 'colisap-perm-group'])
                    ->schema([
                        Forms\Components\CheckboxList::make("permission_groups.{$key}")
                            ->label($group['label'])
                            ->hiddenLabel()
                            ->options($permissions->get($key, collect())->all())
                            ->columns(['default' => 1, 'sm' => 2])
                            ->gridDirection('row')
                            ->bulkToggleable($permissions->get($key, collect())->count() > 1)
                            ->extraAttributes(['class' => 'colisap-perm-list']),
                    ]))->values()->all(),
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
                Tables\Actions\EditAction::make()
                    ->modalHeading(fn (Role $record) => 'Edit '.(Permissions::ROLE_LABELS[$record->name] ?? $record->name))
                    ->modalWidth(MaxWidth::FiveExtraLarge)
                    ->mutateRecordDataUsing(fn (array $data, Role $record): array => [
                        ...$data,
                        'permission_groups' => static::permissionGroupsOf($record),
                    ])
                    // The permission checkboxes are not columns of the role: they are synced (and audited) after the save.
                    ->using(function (Role $record, array $data): Role {
                        $permissions = collect($data['permission_groups'] ?? [])->flatten()->values()->all();
                        unset($data['permission_groups']);

                        $record->update($data);
                        static::syncAndAudit($record, $permissions);

                        return $record;
                    })
                    ->successNotificationTitle('Role saved'),
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
        ];
    }
}
