<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ContributionsRelationManager extends RelationManager
{
    protected static string $relationship = 'contributions';

    protected static ?string $title = 'Contributions';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('amount')
                    ->numeric()
                    ->prefix('₱')
                    ->required(),
                Forms\Components\DatePicker::make('contribution_date')
                    ->default(now())
                    ->required(),
                Forms\Components\TextInput::make('reference_no')
                    ->label('OR / Reference No.')
                    ->maxLength(255),
                Forms\Components\Textarea::make('remarks')
                    ->columnSpanFull(),
                Forms\Components\Hidden::make('recorded_by')
                    ->default(fn () => Auth::id()),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference_no')
            ->columns([
                Tables\Columns\TextColumn::make('contribution_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->money('PHP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('reference_no')
                    ->label('OR / Reference No.'),
                Tables\Columns\TextColumn::make('recorder.name')
                    ->label('Recorded By'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('contribution_date', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
