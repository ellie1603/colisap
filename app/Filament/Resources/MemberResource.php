<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberResource\Pages;
use App\Filament\Resources\MemberResource\RelationManagers;
use App\Models\Member;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Members';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Membership')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('member_no')
                            ->label('Member No.')
                            ->default(fn () => Member::generateNextMemberNo())
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Forms\Components\DatePicker::make('membership_date')
                            ->required()
                            ->default(now()),
                        Forms\Components\Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                                'deceased' => 'Deceased',
                                'withdrawn' => 'Withdrawn',
                            ])
                            ->default('active')
                            ->required()
                            ->live(),
                        Forms\Components\DatePicker::make('date_deceased')
                            ->visible(fn (Forms\Get $get) => $get('status') === 'deceased'),
                    ]),
                Forms\Components\Section::make('Personal Information')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('first_name')->required()->maxLength(255),
                        Forms\Components\TextInput::make('middle_name')->maxLength(255),
                        Forms\Components\TextInput::make('last_name')->required()->maxLength(255),
                        Forms\Components\TextInput::make('suffix')->maxLength(255),
                        Forms\Components\DatePicker::make('birthdate'),
                        Forms\Components\Select::make('sex')
                            ->options(['male' => 'Male', 'female' => 'Female']),
                        Forms\Components\TextInput::make('contact_number')->maxLength(255),
                        Forms\Components\TextInput::make('email')->email()->maxLength(255),
                        Forms\Components\Textarea::make('address')->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Notes')
                    ->schema([
                        Forms\Components\Textarea::make('remarks')->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('member_no')
                    ->label('Member No.')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Name')
                    ->getStateUsing(fn (Member $record) => $record->fullName())
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $q) use ($search) {
                            $q->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('member_no', 'like', "%{$search}%");
                        });
                    }),
                Tables\Columns\TextColumn::make('contact_number')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('membership_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        'deceased' => 'danger',
                        'withdrawn' => 'warning',
                    }),
                Tables\Columns\IconColumn::make('eligible')
                    ->label('Eligible')
                    ->getStateUsing(fn (Member $record) => $record->isEligible())
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'deceased' => 'Deceased',
                        'withdrawn' => 'Withdrawn',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('last_name');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ContributionsRelationManager::class,
            RelationManagers\BeneficiariesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMembers::route('/'),
            'create' => Pages\CreateMember::route('/create'),
            'edit' => Pages\EditMember::route('/{record}/edit'),
        ];
    }

    public static function getGlobalSearchResultTitle($record): string
    {
        return $record->fullName();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['member_no', 'first_name', 'last_name'];
    }
}
