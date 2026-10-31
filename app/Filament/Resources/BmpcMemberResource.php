<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BmpcMemberResource\Pages\ListBmpcMembers;
use App\Filament\Tables\TableFilters;
use App\Models\BmpcMember;
use App\Models\Branch;
use App\Models\Member;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Js;

/**
 * Every Barbaza MPC member, imported from the coop's Excel list — the people who may join COLISAP.
 * Not COLISAP data: it only fills in new and active member transactions. Staff who can import may also correct
 * a member's details by hand; the next import of the same account number overwrites them with the file's values.
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
     * the Acct. Number and the name may be left empty.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
            ->schema([
                // The account number is how every import matches a member: typed once when adding, never changed after.
                Forms\Components\TextInput::make('account_no')
                    ->label('Acct. Number')
                    ->required()
                    ->maxLength(30)
                    ->regex('/^[0-9A-Za-z\-]+$/')
                    ->unique(ignoreRecord: true)
                    ->disabledOn('edit')
                    ->dehydrated(fn (string $operation) => $operation === 'create'),
                Forms\Components\TextInput::make('account_name')->label('Account Name')->maxLength(255)
                    ->helperText(fn (string $operation) => $operation === 'create' ? 'Left empty, it is made from the name: LAST, FIRST MIDDLE.' : null),
                Forms\Components\TextInput::make('last_name')->required()->maxLength(255),
                Forms\Components\TextInput::make('first_name')->required()->maxLength(255),
                Forms\Components\TextInput::make('middle_name')->maxLength(255),
                Forms\Components\TextInput::make('suffix')->maxLength(20),
                Forms\Components\Select::make('branch_id')
                    ->label('Branch')
                    ->options(fn () => Branch::options())
                    ->default(fn () => Branch::restrictedId())
                    ->searchable()
                    // Staff limited to one branch keep the member in that branch.
                    ->disabled(fn () => Branch::restrictedId() !== null)
                    ->dehydrated(),
                Forms\Components\DatePicker::make('birthdate')->maxDate(now())->live(onBlur: true),
                Forms\Components\Placeholder::make('age')
                    ->label('Age')
                    ->content(fn (Forms\Get $get) => $get('birthdate') ? Carbon::parse($get('birthdate'))->age.' yrs' : 'From the birthdate'),
                Forms\Components\Select::make('sex')->options(['male' => 'Male', 'female' => 'Female']),
                Forms\Components\TextInput::make('contact_number')->label('Contact number')->tel()->maxLength(50),
                Forms\Components\TextInput::make('savings_balance')->label('Savings balance')->numeric()->prefix('₱'),
                Forms\Components\DatePicker::make('last_transaction_date')->label('Last transaction date')->maxDate(now()),
                Forms\Components\Select::make('segment')->label('Segmentation')->options(Member::SEGMENTS)->placeholder('NA'),
                Forms\Components\Select::make('category')->label('Category')->options(fn () => Member::categoryOptions())->placeholder('None'),
                Forms\Components\Select::make('remarks')->options(BmpcMember::REMARKS)->placeholder('None'),
                Forms\Components\TextInput::make('address')->maxLength(255)->columnSpanFull(),
            ]);
    }

    /**
     * A member added by hand: their Account Name from the name when none is typed, and the branch of staff limited
     * to one branch.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withCreationDefaults(array $data): array
    {
        $composedName = mb_strtoupper(trim(preg_replace('/\s+/', ' ', "{$data['last_name']}, {$data['first_name']} ".($data['middle_name'] ?? '')) ?? '', ' ,'));

        return [
            ...$data,
            'account_name' => filled($data['account_name'] ?? null) ? $data['account_name'] : $composedName,
            'branch_id' => Branch::restrictedId() ?? ($data['branch_id'] ?? null),
            'imported_by' => auth()->id(),
        ];
    }

    /**
     * What the View window shows, sent with each table row so the window opens in the browser without a server
     * round trip. Details come in pairs, one pair per table row (label, value, label, value); a null pair spot
     * leaves the cell empty so a long value (the address) can use the whole row.
     *
     * @return array{key: string, title: string, canEdit: bool, rows: list<list<array{0: string, 1: string}>>}
     */
    public static function viewDetails(BmpcMember $record): array
    {
        $value = fn (mixed $value): string => filled($value) ? (string) $value : '—';

        return [
            'key' => (string) $record->getKey(),
            'title' => $record->account_name ?? "{$record->last_name}, {$record->first_name}",
            'canEdit' => auth()->user()?->can('update', $record) ?? false,
            'rows' => [
                [['Acct. Number', $value($record->account_no)], ['Account Name', $value($record->account_name)]],
                [['Last name', $value($record->last_name)], ['First name', $value($record->first_name)]],
                [['Middle name', $value($record->middle_name)], ['Suffix', $value($record->suffix)]],
                [['Branch', $value($record->branch?->name)], ['Sex', $value($record->sex ? ucfirst($record->sex) : null)]],
                [['Birthdate', $value($record->birthdate?->format('M j, Y'))], ['Age', $value($record->age())]],
                [['Contact number', $value($record->contact_number)], ['Savings balance', $value($record->savings_balance !== null ? '₱'.number_format((float) $record->savings_balance, 2) : null)]],
                [['Last transaction date', $value($record->last_transaction_date?->format('M j, Y'))], ['Segmentation', Member::SEGMENTS[$record->segment] ?? 'NA']],
                [['Category', $value($record->category ? Member::categoryLabel($record->category) : null)], ['Remarks', $value(BmpcMember::REMARKS[$record->remarks] ?? null)]],
                [['Address', $value($record->address)]],
            ],
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('branch'))
            ->searchPlaceholder('Search name or Acct. Number')
            // The everyday columns only, so View and Edit stay in sight; every other detail is in the View window.
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
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->sortable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('savings_balance')
                    ->label('Savings balance')
                    ->money('PHP')
                    ->sortable()
                    ->alignEnd()
                    ->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('last_transaction_date')->label('Last transaction')->date()->sortable()->visibleFrom('xl'),
                Tables\Columns\TextColumn::make('segment')->label('Segmentation')
                    ->state(fn (BmpcMember $record) => Member::SEGMENTS[$record->segment] ?? 'NA')
                    ->visibleFrom('2xl'),
                Tables\Columns\TextColumn::make('remarks')
                    ->formatStateUsing(fn (?string $state) => BmpcMember::REMARKS[$state] ?? $state)
                    ->badge()
                    ->color(fn (?string $state) => $state === 'deceased' || $state === 'deactivated' ? 'danger' : 'warning')
                    ->visibleFrom('xl'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->placeholder('All branches')
                    ->options(fn () => Branch::options())
                    // Staff limited to one branch only ever see that branch, so the filter would have one choice.
                    ->visible(fn () => Branch::restrictedId() === null),
                TableFilters::segmentation(),
                Tables\Filters\SelectFilter::make('remarks')->placeholder('All remarks')->options(BmpcMember::REMARKS),
                TableFilters::transactionDate('last_transaction_date', 'Last transaction', canBeMissing: true),
            ])
            ->persistFiltersInSession()
            ->actions([
                // Opens the page's View window in the browser (see list-bmpc-members.blade.php): no server round trip.
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->iconButton()
                    ->alpineClickHandler(fn (BmpcMember $record) => '$dispatch(\'bmpc-member-view\', '.Js::from(static::viewDetails($record))->toHtml().')'),
                Tables\Actions\EditAction::make()
                    ->iconButton()
                    ->modalHeading(fn (BmpcMember $record) => "Edit {$record->account_name}"),
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
