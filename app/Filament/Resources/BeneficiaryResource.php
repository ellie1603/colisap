<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BeneficiaryResource\Pages;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\Member;
use App\Services\Policy\PolicySettings;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BeneficiaryResource extends Resource
{
    protected static ?string $model = Beneficiary::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Members';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
            ->schema([
                Forms\Components\Select::make('member_id')
                    ->label('Member')
                    ->relationship('member', 'account_name')
                    ->getOptionLabelFromRecordUsing(fn (Member $record) => "{$record->account_no} — {$record->account_name}")
                    ->searchable(['account_no', 'account_name', 'first_name', 'last_name'])
                    ->live()
                    ->disabledOn('edit')
                    ->required()
                    ->rule(fn (?Model $record) => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                        $max = app(PolicySettings::class)->int('max_beneficiaries');
                        $count = Beneficiary::where('member_id', $value)->where('is_active', true)->whereKeyNot($record?->getKey())->count();

                        if (! $record && $count >= $max) {
                            $fail("This member already has the maximum of {$max} beneficiaries.");
                        }
                    })
                    ->columnSpan(['sm' => 2]),
                Forms\Components\TextInput::make('full_name')->required()->maxLength(255),
                Forms\Components\Select::make('relationship')->options(Beneficiary::RELATIONSHIPS)->required(),
                Forms\Components\TextInput::make('share_percentage')
                    ->label('Share (%)')
                    ->numeric()
                    ->minValue(0.01)
                    ->maxValue(100)
                    ->suffix('%')
                    ->required()
                    ->helperText(function (Forms\Get $get, ?Model $record) {
                        if (! $get('member_id')) {
                            return null;
                        }

                        $others = (float) Beneficiary::where('member_id', $get('member_id'))->where('is_active', true)->whereKeyNot($record?->getKey())->sum('share_percentage');

                        return 'Other beneficiaries hold '.rtrim(rtrim(number_format($others, 2), '0'), '.').'% — shares must total 100%.';
                    })
                    ->rule(fn (Forms\Get $get, ?Model $record) => function (string $attribute, mixed $value, Closure $fail) use ($get, $record) {
                        $others = (float) Beneficiary::where('member_id', $get('member_id'))->where('is_active', true)->whereKeyNot($record?->getKey())->sum('share_percentage');

                        if ($get('is_active') && round($others + (float) $value, 2) > 100) {
                            $fail('Shares would total '.round($others + (float) $value, 2).'%. The total cannot exceed 100%.');
                        }
                    }),
                Forms\Components\TextInput::make('priority')->numeric()->minValue(1)->maxValue(10)->default(1),
                Forms\Components\DatePicker::make('birthdate')->maxDate(now()),
                Forms\Components\TextInput::make('contact_number')->tel()->maxLength(50),
                Forms\Components\Select::make('id_type')->label('ID type')->options(Beneficiary::ID_TYPES),
                Forms\Components\TextInput::make('id_number')->label('ID number')->maxLength(100),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active designation')
                    ->default(true)
                    ->live()
                    ->helperText('Turn off instead of deleting to keep the designation in history.'),
                Forms\Components\Textarea::make('address')->rows(2)->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('member.branch'))
            ->columns([
                Tables\Columns\TextColumn::make('member.account_no')
                    ->label('Acct. Number')
                    ->fontFamily('mono')
                    ->searchable(),
                Tables\Columns\TextColumn::make('member.account_name')
                    ->label('Member')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('member', fn (Builder $query) => $query->searchName($search)))
                    ->wrap(),
                Tables\Columns\TextColumn::make('full_name')->label('Beneficiary')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('relationship')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('share_percentage')->label('Share')->suffix('%')->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('member.branch.name')->label('Branch')->toggleable()->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('contact_number')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active designation'),
                Tables\Filters\SelectFilter::make('branch')
                    ->options(fn () => Branch::options())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $branch) => $query->whereHas('member', fn (Builder $q) => $q->where('branch_id', $branch)))),
                Tables\Filters\SelectFilter::make('relationship')->options(Beneficiary::RELATIONSHIPS),
                Tables\Filters\TrashedFilter::make()->label('Removed beneficiaries'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton(),
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->label('Remove')->iconButton(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->defaultSort('member_id')
            ->poll('60s');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
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
