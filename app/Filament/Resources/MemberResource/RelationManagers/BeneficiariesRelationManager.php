<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use App\Models\Beneficiary;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class BeneficiariesRelationManager extends RelationManager
{
    protected static string $relationship = 'beneficiaries';

    protected static ?string $title = 'Beneficiaries';

    protected static ?string $icon = 'heroicon-o-user-group';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->modifyQueryUsing(fn ($query) => $query->withTrashed())
            ->description("Add, edit or remove beneficiaries from the member's Edit page. Removed beneficiaries stay listed here for history.")
            ->columns([
                Tables\Columns\TextColumn::make('priority')->label('#'),
                Tables\Columns\TextColumn::make('full_name')
                    ->description(fn (Beneficiary $record) => $record->trashed() ? 'Removed '.$record->deleted_at->toFormattedDateString() : null),
                Tables\Columns\TextColumn::make('relationship'),
                Tables\Columns\TextColumn::make('share_percentage')->label('Share')->suffix('%'),
                Tables\Columns\TextColumn::make('birthdate')->date()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('contact_number')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('id_number')->label('ID')
                    ->formatStateUsing(fn (Beneficiary $record) => trim("{$record->id_type} {$record->id_number}"))
                    ->visibleFrom('lg'),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean()
                    ->state(fn (Beneficiary $record) => $record->is_active && ! $record->trashed()),
            ])
            ->defaultSort('priority');
    }
}
