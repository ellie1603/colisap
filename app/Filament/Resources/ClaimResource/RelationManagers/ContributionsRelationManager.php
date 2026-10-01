<?php

namespace App\Filament\Resources\ClaimResource\RelationManagers;

use App\Models\Contribution;
use App\Models\Member;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContributionsRelationManager extends RelationManager
{
    protected static string $relationship = 'contributions';

    protected static ?string $title = 'Participant contributions';

    protected static ?string $icon = 'heroicon-o-hand-raised';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('member'))
            ->columns([
                Tables\Columns\TextColumn::make('member.account_no')->label('Acct. Number')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('member.account_name')->label('Member')->searchable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('segment')->formatStateUsing(fn (?string $state) => Member::segmentLabel($state))->default('—')->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('amount')->money('PHP'),
                Tables\Columns\TextColumn::make('member_share')->money('PHP')->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('coop_share')->label('Coop share')->money('PHP')->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => Contribution::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => Contribution::statusColor($state)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(Contribution::STATUSES),
            ]);
    }
}
