<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use App\Models\ReplenishmentNotice;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ReplenishmentNoticesRelationManager extends RelationManager
{
    protected static string $relationship = 'replenishmentNotices';

    protected static ?string $title = 'Replenishment';

    protected static ?string $icon = 'heroicon-o-bell-alert';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('notice_date')->date(),
                Tables\Columns\TextColumn::make('deadline')->date(),
                Tables\Columns\TextColumn::make('required_balance')->money('PHP')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('shortfall')->money('PHP'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => ReplenishmentNotice::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => ReplenishmentNotice::statusColor($state)),
                Tables\Columns\TextColumn::make('resolved_at')->date()->visibleFrom('md'),
            ])
            ->defaultSort('notice_date', 'desc');
    }
}
