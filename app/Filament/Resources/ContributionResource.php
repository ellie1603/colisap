<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContributionResource\Pages;
use App\Models\Contribution;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ContributionResource extends Resource
{
    protected static ?string $model = Contribution::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Contributions';

    protected static ?int $navigationSort = 2;

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
                Forms\Components\TextInput::make('amount')
                    ->required()
                    ->numeric()
                    ->prefix('₱'),
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('member.member_no')
                    ->label('Member No.')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('member.full_name')
                    ->label('Member Name')
                    ->getStateUsing(fn (Contribution $record) => $record->member?->fullName()),
                Tables\Columns\TextColumn::make('amount')
                    ->money('PHP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('contribution_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('reference_no')
                    ->label('OR / Reference No.')
                    ->searchable(),
                Tables\Columns\TextColumn::make('recorder.name')
                    ->label('Recorded By'),
            ])
            ->filters([
                Tables\Filters\Filter::make('contribution_date')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('contribution_date', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('contribution_date', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('contribution_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContributions::route('/'),
            'create' => Pages\CreateContribution::route('/create'),
            'edit' => Pages\EditContribution::route('/{record}/edit'),
        ];
    }
}
