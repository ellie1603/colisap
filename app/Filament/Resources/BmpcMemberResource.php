<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BmpcMemberResource\Pages;
use App\Models\BmpcMember;
use App\Models\Branch;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every Barbaza MPC member, imported from the coop's Excel list — the people who may join COLISAP.
 * Not COLISAP data: it only fills in new and active member transactions.
 */
class BmpcMemberResource extends Resource
{
    protected static ?string $model = BmpcMember::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Members';

    protected static ?string $navigationLabel = 'BMPC Members';

    protected static ?string $modelLabel = 'BMPC member';

    protected static ?string $pluralModelLabel = 'BMPC members';

    protected static ?int $navigationSort = 2;

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(['default' => 1, 'sm' => 2])
            ->schema([
                Infolists\Components\TextEntry::make('account_no')->label('Acct. Number')->fontFamily('mono')->copyable(),
                Infolists\Components\TextEntry::make('account_name')->label('Account Name'),
                Infolists\Components\TextEntry::make('branch.name')->label('Branch')->placeholder('—'),
                Infolists\Components\TextEntry::make('birthdate')->date()->placeholder('—'),
                Infolists\Components\TextEntry::make('sex')->formatStateUsing(fn (?string $state) => $state ? ucfirst($state) : null)->placeholder('—'),
                Infolists\Components\TextEntry::make('contact_number')->label('Contact number')->placeholder('—'),
                Infolists\Components\TextEntry::make('savings_balance')->label('Savings balance')->money('PHP')->placeholder('—'),
                Infolists\Components\TextEntry::make('updated_at')->label('Last imported')->since(),
                Infolists\Components\TextEntry::make('address')->placeholder('—')->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('branch'))
            ->columns([
                Tables\Columns\TextColumn::make('account_no')
                    ->label('Acct. Number')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('account_name')
                    ->label('Account Name')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->searchName($search))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('last_name', $direction)->orderBy('first_name', $direction))
                    ->wrap(),
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->placeholder('—')
                    ->sortable()
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('birthdate')
                    ->date()
                    ->placeholder('—')
                    ->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('contact_number')
                    ->label('Contact no.')
                    ->placeholder('—')
                    ->visibleFrom('xl'),
                Tables\Columns\TextColumn::make('savings_balance')
                    ->label('Saving Balance')
                    ->money('PHP')
                    ->placeholder('—')
                    ->sortable()
                    ->alignEnd()
                    ->visibleFrom('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(fn () => Branch::options())
                    ->multiple()
                    ->searchable()
                    // Staff limited to one branch only ever see that branch, so the filter would have one choice.
                    ->visible(fn () => Branch::restrictedId() === null),
            ])
            ->persistFiltersInSession()
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton()->modalHeading(fn (BmpcMember $record) => $record->account_name),
            ])
            ->defaultSort('account_name')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->striped()
            ->emptyStateHeading('No BMPC members yet')
            ->emptyStateDescription('Import the coop\'s Excel member list to fill this in.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBmpcMembers::route('/'),
        ];
    }
}
