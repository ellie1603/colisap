<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BmpcMemberResource\Pages\ListBmpcMembers;
use App\Filament\Tables\TableFilters;
use App\Models\BmpcMember;
use App\Models\Branch;
use App\Models\Member;
use App\Services\Masterlist\NameParser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Js;

/**
 * Every Barbaza MPC member, imported from the coop's Excel list — the people who may join COLISAP.
 * Not COLISAP data: it only fills in new and active member transactions. A member is identified by their CIF Key;
 * their savings number is the savings account the balance belongs to and the Acct. Number COLISAP knows them by.
 * Staff who can import may also correct a member's details by hand; the next import of the same CIF Key
 * overwrites them with the file's values.
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

    /**
     * Adding a member and correcting one use the same form, laid out in the table's column order. Every field but
     * the CIF Key and the Account Name may be left empty.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
            ->schema([
                Forms\Components\TextInput::make('savings_no')
                    ->label('Savings number')
                    ->maxLength(30)
                    ->regex('/^[0-9A-Za-z\-]+$/')
                    ->helperText('The savings account the balance belongs to.'),
                // The CIF Key is how every import matches a member: typed once when adding, never changed after.
                Forms\Components\TextInput::make('cif_key')
                    ->label('CIF Key')
                    ->required()
                    ->maxLength(30)
                    ->regex('/^[0-9A-Za-z\-]+$/')
                    ->unique(ignoreRecord: true)
                    ->disabledOn('edit')
                    ->dehydrated(fn (string $operation) => $operation === 'create')
                    ->helperText(fn (string $operation) => $operation === 'create' ? 'The member\'s unique number.' : null),
                Forms\Components\TextInput::make('account_name')
                    ->label('Account Name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('LAST, FIRST MIDDLE'),
                Forms\Components\Select::make('branch_id')
                    ->label('Branch')
                    ->options(fn () => Branch::codedOptions())
                    ->default(fn () => Branch::restrictedId())
                    ->searchable()
                    // Staff limited to one branch keep the member in that branch.
                    ->disabled(fn () => Branch::restrictedId() !== null)
                    ->dehydrated(),
                Forms\Components\DatePicker::make('birthdate')->maxDate(now()),
                Forms\Components\TextInput::make('contact_number')->label('Contact Number')->tel()->maxLength(50),
                Forms\Components\TextInput::make('savings_balance')->label('Savings Balance')->numeric()->prefix('₱'),
                Forms\Components\DatePicker::make('last_transaction_date')->label('Last Transaction Date')->maxDate(now()),
                Forms\Components\Select::make('segment')->label('Segmentation')->options(Member::SEGMENTS)->placeholder('NA'),
                Forms\Components\Select::make('category')->label('Category')->options(fn () => Member::categoryOptions())->placeholder('None'),
                Forms\Components\TextInput::make('status')->maxLength(100)->datalist(fn () => array_values(BmpcMember::distinctValues('status'))),
                Forms\Components\TextInput::make('remarks')->maxLength(255)->datalist(fn () => array_values(BmpcMember::distinctValues('remarks'))),
            ]);
    }

    /**
     * A member added by hand: the branch of staff limited to one branch, and the name parts.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withCreationDefaults(array $data): array
    {
        return [
            ...static::withNameParts($data),
            'branch_id' => Branch::restrictedId() ?? ($data['branch_id'] ?? null),
            'imported_by' => auth()->id(),
        ];
    }

    /**
     * The last, first and middle name and suffix, worked out from the Account Name: a new transaction filled in from
     * the member needs them.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withNameParts(array $data): array
    {
        if (blank($data['account_name'] ?? null)) {
            return $data;
        }

        $nameParser = app(NameParser::class);
        $accountName = $nameParser->surnameFirst($data['account_name']);
        $name = $nameParser->parse($accountName);

        return [
            ...$data,
            'account_name' => $accountName,
            'last_name' => $name['last_name'] ?: null,
            'first_name' => $name['first_name'] ?: null,
            'middle_name' => $name['middle_name'] ?: null,
            'suffix' => $name['suffix'] ?: null,
        ];
    }

    /**
     * What the View window shows, sent with each table row so the window opens in the browser without a server
     * round trip. Details come in pairs, one pair per table row (label, value, label, value).
     *
     * @return array{key: string, title: string, canEdit: bool, rows: list<list<array{0: string, 1: string}>>}
     */
    public static function viewDetails(BmpcMember $record): array
    {
        $value = fn (mixed $value): string => filled($value) ? (string) $value : '—';

        return [
            'key' => (string) $record->getKey(),
            'title' => $record->account_name ?? (string) $record->cif_key,
            'canEdit' => auth()->user()?->can('update', $record) ?? false,
            'rows' => [
                [['Savings number', $value($record->savings_no)], ['CIF Key', $value($record->cif_key)]],
                [['Account Name', $value($record->account_name)], ['Branch', $value($record->branch ? "{$record->branch->code} - {$record->branch->name}" : null)]],
                [['Birthdate', $value($record->birthdate?->format('M j, Y'))], ['Contact Number', $value($record->contact_number)]],
                [['Savings Balance', $value($record->savings_balance !== null ? '₱'.number_format((float) $record->savings_balance, 2) : null)], ['Last Transaction Date', $value($record->last_transaction_date?->format('M j, Y'))]],
                [['Segmentation', Member::SEGMENTS[$record->segment] ?? 'NA'], ['Category', $value($record->category ? Member::categoryLabel($record->category) : null)]],
                [['Status', $value($record->status)], ['Remarks', $value($record->remarks)]],
            ],
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('branch'))
            ->searchPlaceholder('Search name, CIF Key or Savings number')
            // The everyday columns only, so View and Edit stay in sight; every other detail is in the View window.
            ->columns([
                Tables\Columns\TextColumn::make('cif_key')
                    ->label('CIF Key')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('account_name')
                    ->label('Account Name')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->searchName($search))
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('savings_no')
                    ->label('Savings number')
                    ->searchable()
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->sortable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('savings_balance')
                    ->label('Savings Balance')
                    ->money('PHP')
                    ->sortable()
                    ->alignEnd()
                    ->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('last_transaction_date')->label('Last Transaction')->date()->sortable()->visibleFrom('xl'),
                Tables\Columns\TextColumn::make('status')->placeholder('—')->visibleFrom('2xl'),
                Tables\Columns\TextColumn::make('remarks')
                    ->badge()
                    ->color('warning')
                    ->visibleFrom('xl'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->placeholder('All branches')
                    ->options(fn () => Branch::codedOptions())
                    // Staff limited to one branch only ever see that branch, so the filter would have one choice.
                    ->visible(fn () => Branch::restrictedId() === null),
                TableFilters::segmentation(),
                Tables\Filters\SelectFilter::make('status')->placeholder('All statuses')->options(fn () => BmpcMember::distinctValues('status')),
                Tables\Filters\SelectFilter::make('remarks')->placeholder('All remarks')->options(fn () => BmpcMember::distinctValues('remarks')),
            ])
            ->persistFiltersInSession()
            ->actions([
                // Opens the page's View window in the browser (see list-bmpc-members.blade.php): no server round trip.
                // Clicking anywhere else on the row presses this button too.
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->iconButton()
                    ->extraAttributes(['data-row-view' => true])
                    ->alpineClickHandler(fn (BmpcMember $record) => '$dispatch(\'bmpc-member-view\', '.Js::from(static::viewDetails($record))->toHtml().')'),
                Tables\Actions\EditAction::make()
                    ->iconButton()
                    ->modalHeading(fn (BmpcMember $record) => "Edit {$record->account_name}")
                    ->mutateFormDataUsing(fn (array $data): array => static::withNameParts($data)),
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
            'index' => ListBmpcMembers::route('/'),
        ];
    }
}
