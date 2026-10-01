<?php

namespace App\Filament\Resources\ClaimResource\RelationManagers;

use App\Models\ClaimDocument;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    protected static ?string $icon = 'heroicon-o-paper-clip';

    public const TYPES = [
        'Death Certificate' => 'Death Certificate',
        'COLISAP Certificate' => 'COLISAP Certificate',
        'Approved COLISAP Application' => 'Approved COLISAP Application (duplicate)',
        'Valid ID' => 'Valid ID of claimant',
        'Proof of Relationship' => 'Proof of Relationship',
        'Loan / Obligation Statement' => 'Loan / Obligation Statement',
        'Other' => 'Other',
    ];

    /**
     * Documents can be added from the claim page while the claim is being processed.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    private function canManage(): bool
    {
        return (Auth::user()?->can('claims.manage') ?? false)
            && in_array($this->getOwnerRecord()->status, ['submitted', 'under_review', 'approved'], true);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('document_type')->options(self::TYPES)->required(),
                Forms\Components\FileUpload::make('file_path')
                    ->label('File')
                    ->disk('local')
                    ->directory(fn ($livewire) => 'claims/'.$livewire->getOwnerRecord()->id)
                    ->visibility('private')
                    ->storeFileNamesIn('original_filename')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                    ->maxSize(10240)
                    ->helperText('PDF or image, up to 10 MB.')
                    ->required(),
                Forms\Components\Hidden::make('uploaded_by')->default(fn () => Auth::id()),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('document_type')
            ->columns([
                Tables\Columns\TextColumn::make('document_type'),
                Tables\Columns\TextColumn::make('original_filename')->label('File')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('uploader.name')->label('Uploaded by')->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->visibleFrom('md'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Upload document')->visible(fn () => $this->canManage()),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (ClaimDocument $record) => route('claim-documents.download', $record))
                    ->openUrlInNewTab(),
                Tables\Actions\DeleteAction::make()->visible(fn () => $this->canManage()),
            ]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('claims.view') ?? false;
    }
}
