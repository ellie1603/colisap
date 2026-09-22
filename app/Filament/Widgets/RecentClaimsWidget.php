<?php

namespace App\Filament\Widgets;

use App\Models\Claim;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentClaimsWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Recent Claims';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Claim::query()->latest('date_filed')
            )
            ->columns([
                Tables\Columns\TextColumn::make('claim_no'),
                Tables\Columns\TextColumn::make('member.full_name')
                    ->label('Member')
                    ->getStateUsing(fn (Claim $record) => $record->member?->fullName()),
                Tables\Columns\TextColumn::make('date_filed')->date(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Claim::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'submitted' => 'gray',
                        'under_review' => 'warning',
                        'approved' => 'success',
                        'paid' => 'info',
                        'rejected' => 'danger',
                    }),
                Tables\Columns\TextColumn::make('claim_amount')->money('PHP'),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
