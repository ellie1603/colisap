<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use App\Models\MemberHistory;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class HistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'histories';

    protected static ?string $title = 'History';

    protected static ?string $icon = 'heroicon-o-clock';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('changed_at')->label('When')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('field')->badge()->formatStateUsing(fn (string $state) => ucfirst($state)),
                Tables\Columns\TextColumn::make('change')
                    ->state(fn (MemberHistory $record) => $record->displayValue($record->old_value).' → '.$record->displayValue($record->new_value)),
                Tables\Columns\TextColumn::make('reason')->wrap()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('source')->badge()->color('gray')->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('changer.name')->label('By')->default('System')->visibleFrom('lg'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('field')->options([
                    'status' => 'Status',
                    'category' => 'Category',
                    'segment' => 'Segmentation',
                    'branch' => 'Branch',
                ]),
            ])
            ->defaultSort('changed_at', 'desc');
    }
}
