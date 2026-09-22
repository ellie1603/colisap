<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClaimResource\Pages;
use App\Filament\Resources\ClaimResource\RelationManagers;
use App\Models\Claim;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ClaimResource extends Resource
{
    protected static ?string $model = Claim::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Claims';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Claim Details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('member_id')
                            ->label('Deceased Member')
                            ->relationship('member', 'member_no')
                            ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->member_no} — {$record->fullName()}")
                            ->searchable(['member_no', 'first_name', 'last_name'])
                            ->preload()
                            ->live()
                            ->required(),
                        Forms\Components\Select::make('beneficiary_id')
                            ->label('Claimant / Beneficiary')
                            ->options(fn (Forms\Get $get) => \App\Models\Beneficiary::query()
                                ->where('member_id', $get('member_id'))
                                ->pluck('full_name', 'id'))
                            ->searchable()
                            ->required(),
                        Forms\Components\DatePicker::make('date_of_death')
                            ->required(),
                        Forms\Components\DatePicker::make('date_filed')
                            ->default(now())
                            ->required(),
                        Forms\Components\TextInput::make('claim_amount')
                            ->numeric()
                            ->prefix('₱')
                            ->helperText('Leave blank to compute from the active eligibility rule\'s benefit amount.'),
                        Forms\Components\Textarea::make('remarks')
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Review & Approval')
                    ->columns(2)
                    ->visibleOn('edit')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->options(Claim::STATUSES)
                            ->disabled()
                            ->dehydrated(),
                        Forms\Components\TextInput::make('approved_amount')
                            ->numeric()
                            ->prefix('₱')
                            ->disabled(fn (Forms\Get $get) => ! in_array($get('status'), ['approved', 'paid'])),
                        Forms\Components\Textarea::make('rejection_reason')
                            ->columnSpanFull()
                            ->visible(fn (Forms\Get $get) => $get('status') === 'rejected')
                            ->disabled(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('claim_no')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('member.full_name')
                    ->label('Member')
                    ->getStateUsing(fn (Claim $record) => $record->member?->fullName())
                    ->searchable(),
                Tables\Columns\TextColumn::make('beneficiary.full_name')
                    ->label('Claimant'),
                Tables\Columns\TextColumn::make('date_of_death')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_filed')
                    ->date()
                    ->sortable(),
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
                Tables\Columns\TextColumn::make('claim_amount')
                    ->money('PHP')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(Claim::STATUSES),
            ])
            ->actions([
                Tables\Actions\Action::make('review')
                    ->label('Start Review')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('warning')
                    ->visible(fn (Claim $record) => $record->status === 'submitted')
                    ->requiresConfirmation()
                    ->action(function (Claim $record) {
                        $record->markUnderReview(Auth::user());
                        Notification::make()->title('Claim moved to Under Review')->success()->send();
                    }),
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Claim $record) => $record->status === 'under_review' && Auth::user()->hasRole('admin'))
                    ->form([
                        Forms\Components\TextInput::make('approved_amount')
                            ->numeric()
                            ->prefix('₱')
                            ->default(fn (Claim $record) => $record->claim_amount),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Claim $record, array $data) {
                        $record->approve(Auth::user(), $data['approved_amount'] ?? null);
                        Notification::make()->title('Claim approved')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Claim $record) => in_array($record->status, ['submitted', 'under_review']) && Auth::user()->hasRole('admin'))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Rejection reason')
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Claim $record, array $data) {
                        $record->reject(Auth::user(), $data['reason']);
                        Notification::make()->title('Claim rejected')->danger()->send();
                    }),
                Tables\Actions\Action::make('markPaid')
                    ->label('Mark Paid')
                    ->icon('heroicon-o-banknotes')
                    ->color('info')
                    ->visible(fn (Claim $record) => $record->status === 'approved' && Auth::user()->hasRole('admin'))
                    ->requiresConfirmation()
                    ->action(function (Claim $record) {
                        $record->markPaid();
                        Notification::make()->title('Claim marked as paid')->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClaims::route('/'),
            'create' => Pages\CreateClaim::route('/create'),
            'edit' => Pages\EditClaim::route('/{record}/edit'),
        ];
    }
}
