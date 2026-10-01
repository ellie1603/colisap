<?php

namespace App\Filament\Resources\ImportBatchResource\RelationManagers;

use App\Filament\Resources\MemberResource;
use App\Models\ImportRow;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class RowsRelationManager extends RelationManager
{
    protected static string $relationship = 'rows';

    protected static ?string $title = 'Rows';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sheet')->searchable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('row_number')->label('Row'),
                Tables\Columns\TextColumn::make('account_no')->label('Acct. Number')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('account_name')->label('Account Name')->searchable()->wrap()->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('action')->label('Classification')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'reference' ? 'Side list' : (ImportRow::ACTIONS[$state] ?? $state))
                    ->color(fn (string $state) => $state === 'reference' ? 'gray' : ImportRow::actionColor($state)),
                Tables\Columns\TextColumn::make('result')->badge()->color('gray')->placeholder('—'),
                Tables\Columns\TextColumn::make('issues')
                    ->state(fn (ImportRow $record) => implode(' · ', [...($record->errors ?? []), ...($record->warnings ?? [])]))
                    ->wrap()
                    ->placeholder('—')
                    ->visibleFrom('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')->label('Classification')->options([...ImportRow::ACTIONS, 'reference' => 'Side list']),
                Tables\Filters\SelectFilter::make('result')->options(['created' => 'Created', 'updated' => 'Updated', 'unchanged' => 'Unchanged']),
            ])
            ->recordUrl(fn (ImportRow $record) => $record->member_id ? MemberResource::getUrl('view', ['record' => $record->member_id]) : null)
            ->defaultSort('id');
    }
}
