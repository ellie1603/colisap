<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use App\Models\Contribution;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ContributionsRelationManager extends RelationManager
{
    protected static string $relationship = 'contributions';

    protected static ?string $title = 'Contributions';

    protected static ?string $icon = 'heroicon-o-hand-raised';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('claim'))
            ->columns([
                Tables\Columns\TextColumn::make('contribution_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('claim.claim_no')->label('Claim'),
                Tables\Columns\TextColumn::make('amount')->money('PHP'),
                Tables\Columns\TextColumn::make('member_share')->money('PHP')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('coop_share')->label('Coop share')->money('PHP')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => Contribution::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => Contribution::statusColor($state)),
            ])
            ->defaultSort('contribution_date', 'desc');
    }
}
