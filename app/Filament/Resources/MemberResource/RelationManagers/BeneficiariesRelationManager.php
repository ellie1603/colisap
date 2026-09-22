<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class BeneficiariesRelationManager extends RelationManager
{
    protected static string $relationship = 'beneficiaries';

    protected static ?string $title = 'Beneficiaries';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('full_name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('relationship')
                    ->options([
                        'Spouse' => 'Spouse',
                        'Child' => 'Child',
                        'Parent' => 'Parent',
                        'Sibling' => 'Sibling',
                        'Other' => 'Other',
                    ])
                    ->required(),
                Forms\Components\DatePicker::make('birthdate'),
                Forms\Components\TextInput::make('contact_number')
                    ->maxLength(255),
                Forms\Components\Textarea::make('address')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('share_percentage')
                    ->label('Share (%)')
                    ->numeric()
                    ->suffix('%'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active designation')
                    ->default(true)
                    ->helperText('Turn off instead of deleting to preserve beneficiary history.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->columns([
                Tables\Columns\TextColumn::make('full_name'),
                Tables\Columns\TextColumn::make('relationship'),
                Tables\Columns\TextColumn::make('contact_number')->toggleable(),
                Tables\Columns\TextColumn::make('share_percentage')
                    ->label('Share')
                    ->suffix('%'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
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
