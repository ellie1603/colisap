<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ImportBatchResource\Pages;
use App\Filament\Resources\ImportBatchResource\RelationManagers;
use App\Models\ImportBatch;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ImportBatchResource extends Resource
{
    protected static ?string $model = ImportBatch::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box-arrow-down';

    protected static ?string $navigationGroup = 'Data & Reports';

    protected static ?string $navigationLabel = 'Import History';

    protected static ?string $modelLabel = 'import';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()
                ->columns(['default' => 2, 'md' => 4])
                ->schema([
                    Infolists\Components\TextEntry::make('id')->label('Import ID')->prefix('#'),
                    Infolists\Components\TextEntry::make('file_name')->label('File')->columnSpan(['md' => 2]),
                    Infolists\Components\TextEntry::make('status')->badge()
                        ->formatStateUsing(fn (string $state) => ImportBatch::STATUSES[$state] ?? $state)
                        ->color(fn (string $state) => ImportBatch::statusColor($state)),
                    Infolists\Components\TextEntry::make('uploader.name')->label('Uploaded by')->placeholder('—'),
                    Infolists\Components\TextEntry::make('created_at')->label('Uploaded')->dateTime(),
                    Infolists\Components\TextEntry::make('as_of_date')->label('Masterlist as of')->date(),
                    Infolists\Components\TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                    Infolists\Components\TextEntry::make('total_rows'),
                    Infolists\Components\TextEntry::make('new_rows')->label('New'),
                    Infolists\Components\TextEntry::make('update_rows')->label('Updates'),
                    Infolists\Components\TextEntry::make('duplicate_rows')->label('Duplicates'),
                    Infolists\Components\TextEntry::make('invalid_rows')->label('Invalid'),
                    Infolists\Components\TextEntry::make('warning_rows')->label('With warnings'),
                    Infolists\Components\TextEntry::make('created_count')->label('Created'),
                    Infolists\Components\TextEntry::make('updated_count')->label('Updated'),
                    Infolists\Components\TextEntry::make('branches')->label('Sheets / branches processed')
                        ->state(fn (ImportBatch $record) => implode(', ', $record->branchNames()) ?: '—')
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('error_message')->placeholder('—')->columnSpanFull()
                        ->visible(fn (ImportBatch $record) => filled($record->error_message)),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('uploader'))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('file_name')->label('File')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('created_at')->label('Uploaded')->dateTime()->sortable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('uploader.name')->label('By')->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('total_rows')->label('Rows')->numeric()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('created_count')->label('New')->numeric()->color('success'),
                Tables\Columns\TextColumn::make('updated_count')->label('Updated')->numeric()->color('info')->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('duplicate_rows')->label('Dupl.')->numeric()->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('invalid_rows')->label('Invalid')->numeric()->color('danger')->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => ImportBatch::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => ImportBatch::statusColor($state)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(ImportBatch::STATUSES),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton(),
                Tables\Actions\Action::make('resume')
                    ->label('Continue')
                    ->icon('heroicon-o-play')
                    ->visible(fn (ImportBatch $record) => in_array($record->status, ['analyzed', 'staged', 'importing'], true) && (auth()->user()?->can('members.import') ?? false))
                    ->url(fn (ImportBatch $record) => MemberResource::getUrl('import', ['batch' => $record->id])),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\RowsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportBatches::route('/'),
            'view' => Pages\ViewImportBatch::route('/{record}'),
        ];
    }
}
