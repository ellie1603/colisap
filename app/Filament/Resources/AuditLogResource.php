<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only, append-only audit trail. No user (including the Administrator) can edit or delete entries here.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Audit Logs';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return Auth::user()?->can('audit.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    Infolists\Components\TextEntry::make('created_at')->label('When')->dateTime(),
                    Infolists\Components\TextEntry::make('user.name')->label('User')->default('System'),
                    Infolists\Components\TextEntry::make('action')->badge(),
                    Infolists\Components\TextEntry::make('module'),
                    Infolists\Components\TextEntry::make('auditable_id')->label('Record ID'),
                    Infolists\Components\TextEntry::make('reason')->placeholder('—'),
                    Infolists\Components\TextEntry::make('ip_address')->label('IP address')->placeholder('—'),
                    Infolists\Components\TextEntry::make('user_agent')->label('Device')->placeholder('—')->columnSpan(2),
                    Infolists\Components\KeyValueEntry::make('old_values')->label('Previous values')->columnSpanFull()
                        ->state(fn (AuditLog $record) => collect($record->old_values ?? [])->map(fn ($value) => is_scalar($value) || $value === null ? $value : json_encode($value))->all()),
                    Infolists\Components\KeyValueEntry::make('new_values')->label('New values')->columnSpanFull()
                        ->state(fn (AuditLog $record) => collect($record->new_values ?? [])->map(fn ($value) => is_scalar($value) || $value === null ? $value : json_encode($value))->all()),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('User')->default('System')->searchable(),
                Tables\Columns\TextColumn::make('action')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('module')->searchable()->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('auditable_id')->label('Record')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('reason')->limit(40)->placeholder('—')->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('ip_address')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('module')
                    ->options(fn () => AuditLog::query()->whereNotNull('module')->distinct()->orderBy('module')->pluck('module', 'module')->all()),
                Tables\Filters\SelectFilter::make('action')
                    ->options(fn () => AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->all()),
                Tables\Filters\SelectFilter::make('user_id')->label('User')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
