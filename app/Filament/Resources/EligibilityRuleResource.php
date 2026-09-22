<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EligibilityRuleResource\Pages;
use App\Models\EligibilityRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EligibilityRuleResource extends Resource
{
    protected static ?string $model = EligibilityRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Eligibility';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('min_membership_months')
                    ->label('Minimum Membership (months)')
                    ->numeric()
                    ->default(0)
                    ->required(),
                Forms\Components\TextInput::make('min_contribution_balance')
                    ->label('Minimum Contribution Balance')
                    ->numeric()
                    ->prefix('₱')
                    ->default(0)
                    ->required(),
                Forms\Components\TextInput::make('max_missed_contributions')
                    ->label('Max Missed Contribution Periods')
                    ->numeric(),
                Forms\Components\TextInput::make('benefit_amount')
                    ->label('Death Benefit Amount')
                    ->numeric()
                    ->prefix('₱'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active rule')
                    ->helperText('Only one rule should be active at a time — it is used to compute member eligibility.')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('min_membership_months')
                    ->label('Min. Months'),
                Tables\Columns\TextColumn::make('min_contribution_balance')
                    ->label('Min. Balance')
                    ->money('PHP'),
                Tables\Columns\TextColumn::make('benefit_amount')
                    ->label('Benefit Amount')
                    ->money('PHP'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEligibilityRules::route('/'),
            'create' => Pages\CreateEligibilityRule::route('/create'),
            'edit' => Pages\EditEligibilityRule::route('/{record}/edit'),
        ];
    }
}
