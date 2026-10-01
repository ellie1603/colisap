<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    protected static ?string $title = 'Audit';

    protected static ?string $icon = 'heroicon-o-shield-check';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('audit.view') ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('action')->badge(),
                Tables\Columns\TextColumn::make('user.name')->label('User')->default('System'),
                Tables\Columns\TextColumn::make('new_values')->label('Changes')
                    ->formatStateUsing(fn ($state) => collect(is_array($state) ? $state : [])
                        ->map(fn ($value, $key) => "{$key}: ".(is_scalar($value) ? $value : json_encode($value)))
                        ->implode('; '))
                    ->limit(80)
                    ->wrap()
                    ->visibleFrom('md'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
