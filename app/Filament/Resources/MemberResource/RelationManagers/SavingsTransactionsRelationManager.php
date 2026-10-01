<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use App\Models\SavingsTransaction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SavingsTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'savingsTransactions';

    protected static ?string $title = 'Savings';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Use Actions → Record savings transaction to add deposits or withdrawals.')
            ->columns([
                Tables\Columns\TextColumn::make('transaction_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('type')->badge()
                    ->formatStateUsing(fn (string $state) => SavingsTransaction::TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('amount')->money('PHP')
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('balance_after')->label('Balance')->money('PHP')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('reference_no')->label('Reference')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('remarks')->limit(40)->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('recorder.name')->label('Recorded by')->default('System')->visibleFrom('lg'),
            ])
            ->defaultSort('transaction_date', 'desc');
    }
}
