<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContributionResource\Pages;
use App\Models\Branch;
use App\Models\Claim;
use App\Models\Contribution;
use App\Models\Member;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mortuary contributions (Policy III.2 and VI) — generated from approved claims.
 */
class ContributionResource extends Resource
{
    protected static ?string $model = Contribution::class;

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?string $navigationGroup = 'Mortuary';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['member.branch', 'claim']))
            ->columns([
                Tables\Columns\TextColumn::make('contribution_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('claim.claim_no')->label('Claim')->searchable()
                    ->url(fn (Contribution $record) => $record->claim ? ClaimResource::getUrl('view', ['record' => $record->claim]) : null),
                Tables\Columns\TextColumn::make('member.account_no')->label('Acct. Number')->fontFamily('mono')->searchable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('member.account_name')->label('Member')->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('member', fn (Builder $query) => $query->searchName($search))),
                Tables\Columns\TextColumn::make('member.branch.name')->label('Branch')->toggleable()->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('segment')->formatStateUsing(fn (?string $state) => Member::segmentLabel($state))->default('—')->toggleable()->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('amount')->money('PHP')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('PHP')),
                Tables\Columns\TextColumn::make('member_share')->money('PHP')->visibleFrom('sm')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('PHP')),
                Tables\Columns\TextColumn::make('coop_share')->label('Coop share')->money('PHP')->visibleFrom('sm')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('PHP')),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => Contribution::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => Contribution::statusColor($state)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('claim_id')->label('Claim')
                    ->options(fn () => Claim::query()->latest()->pluck('claim_no', 'id'))
                    ->searchable(),
                Tables\Filters\SelectFilter::make('status')->options(Contribution::STATUSES),
                Tables\Filters\SelectFilter::make('segment')->options(Member::SEGMENTS),
                Tables\Filters\SelectFilter::make('branch')
                    ->options(fn () => Branch::options())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $branch) => $query->whereHas('member', fn (Builder $q) => $q->where('branch_id', $branch)))),
                Tables\Filters\Filter::make('contribution_date')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('contribution_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('contribution_date', '<=', $date))),
            ])
            ->defaultSort('contribution_date', 'desc')
            ->poll('60s');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContributions::route('/'),
        ];
    }
}
