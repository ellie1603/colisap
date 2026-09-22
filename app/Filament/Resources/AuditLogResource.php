<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Audit Log';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 8;

    public static function canAccess(): bool
    {
        return Auth::user()?->hasAnyRole(['admin', 'auditor']) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('user.name')->label('User')->disabled(),
                Forms\Components\TextInput::make('action')->disabled(),
                Forms\Components\TextInput::make('auditable_type')->disabled(),
                Forms\Components\TextInput::make('auditable_id')->disabled(),
                Forms\Components\Textarea::make('old_values_json')
                    ->label('Old Values')
                    ->formatStateUsing(fn (AuditLog $record) => json_encode($record->old_values, JSON_PRETTY_PRINT))
                    ->disabled()
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('new_values_json')
                    ->label('New Values')
                    ->formatStateUsing(fn (AuditLog $record) => json_encode($record->new_values, JSON_PRETTY_PRINT))
                    ->disabled()
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('ip_address')->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->default('System')
                    ->sortable(),
                Tables\Columns\TextColumn::make('action')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('Record Type')
                    ->formatStateUsing(fn (string $state) => class_basename($state))
                    ->searchable(),
                Tables\Columns\TextColumn::make('auditable_id')
                    ->label('Record ID'),
                Tables\Columns\TextColumn::make('ip_address'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->options([
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
