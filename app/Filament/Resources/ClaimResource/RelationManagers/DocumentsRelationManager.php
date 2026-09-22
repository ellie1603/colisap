<?php

namespace App\Filament\Resources\ClaimResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Supporting Documents';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('document_type')
                    ->options([
                        'Death Certificate' => 'Death Certificate',
                        'Valid ID' => 'Valid ID',
                        'Claim Form' => 'Claim Form',
                        'Proof of Relationship' => 'Proof of Relationship',
                        'Other' => 'Other',
                    ])
                    ->required(),
                Forms\Components\FileUpload::make('file_path')
                    ->label('File')
                    ->disk('local')
                    ->directory(fn ($livewire) => 'claims/'.$livewire->getOwnerRecord()->id)
                    ->visibility('private')
                    ->required(),
                Forms\Components\Hidden::make('uploaded_by')
                    ->default(fn () => Auth::id()),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('document_type')
            ->columns([
                Tables\Columns\TextColumn::make('document_type'),
                Tables\Columns\TextColumn::make('uploader.name')
                    ->label('Uploaded By'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn ($record) => route('claim-documents.download', $record))
                    ->openUrlInNewTab(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
