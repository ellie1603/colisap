<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BeneficiaryResource\Pages;
use App\Models\Beneficiary;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BeneficiaryResource extends Resource
{
    protected static ?string $model = Beneficiary::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Beneficiaries';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('member_id')
                    ->label('Member')
                    ->relationship('member', 'member_no')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->member_no} — {$record->fullName()}")
                    ->searchable(['member_no', 'first_name', 'last_name'])
                    ->preload()
                    ->required(),
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('member.member_no')
                    ->label('Member No.')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('member.full_name')
                    ->label('Member')
                    ->getStateUsing(fn (Beneficiary $record) => $record->member?->fullName()),
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Beneficiary')
                    ->searchable(),
                Tables\Columns\TextColumn::make('relationship'),
                Tables\Columns\TextColumn::make('contact_number')->toggleable(),
                Tables\Columns\TextColumn::make('share_percentage')
                    ->label('Share')
                    ->suffix('%'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active designation'),
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
            'index' => Pages\ListBeneficiaries::route('/'),
            'create' => Pages\CreateBeneficiary::route('/create'),
            'edit' => Pages\EditBeneficiary::route('/{record}/edit'),
        ];
    }
}
