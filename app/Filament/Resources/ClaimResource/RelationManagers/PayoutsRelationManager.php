<?php

namespace App\Filament\Resources\ClaimResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    protected static ?string $title = 'Beneficiary settlement';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Created when the claim is approved: the net benefit split by each active beneficiary\'s share.')
            ->columns([
                Tables\Columns\TextColumn::make('beneficiary_name')->label('Beneficiary'),
                Tables\Columns\TextColumn::make('share_percentage')->label('Share')->suffix('%'),
                Tables\Columns\TextColumn::make('amount')->money('PHP')->weight('bold'),
                Tables\Columns\TextColumn::make('released_at')->label('Released')->dateTime()->placeholder('Pending')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('reference_no')->label('Reference')->visibleFrom('lg'),
            ]);
    }
}
