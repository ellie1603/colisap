<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use App\Filament\Resources\ClaimResource;
use App\Models\Claim;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ClaimsRelationManager extends RelationManager
{
    protected static string $relationship = 'claims';

    protected static ?string $title = 'Claims';

    protected static ?string $icon = 'heroicon-o-document-text';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('claim_no'),
                Tables\Columns\TextColumn::make('date_of_death')->date(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => Claim::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => Claim::statusColor($state)),
                Tables\Columns\TextColumn::make('gross_benefit')->money('PHP')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('net_benefit')->money('PHP'),
            ])
            ->recordUrl(fn (Claim $record) => ClaimResource::getUrl('view', ['record' => $record]));
    }
}
