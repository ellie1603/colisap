<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use App\Filament\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\Member;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ApplicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'applications';

    protected static ?string $title = 'Applications';

    protected static ?string $icon = 'heroicon-o-clipboard-document-check';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('application_date')->date(),
                Tables\Columns\TextColumn::make('type')->formatStateUsing(fn (string $state) => Application::TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('category')->formatStateUsing(fn (string $state) => Member::categoryLabel($state)),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => Application::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => Application::statusColor($state)),
                Tables\Columns\TextColumn::make('approved_at')->label('Decided')->date()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('previous_status')->visibleFrom('lg'),
            ])
            ->recordUrl(fn (Application $record) => ApplicationResource::getUrl('edit', ['record' => $record]))
            ->defaultSort('application_date', 'desc');
    }
}
