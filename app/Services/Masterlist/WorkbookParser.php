<?php

namespace App\Services\Masterlist;

use App\Models\Branch;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Options as CSVOptions;
use OpenSpout\Reader\CSV\Reader as CSVReader;
use OpenSpout\Reader\ODS\Options as ODSOptions;
use OpenSpout\Reader\ODS\Reader as ODSReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XLSXOptions;
use OpenSpout\Reader\XLSX\Reader as XLSXReader;
use Throwable;

/**
 * Streams a multi-sheet COLISAP masterlist workbook (one sheet per branch).
 *  - detectSheets(): finds each sheet's header row, maps columns to fields and suggests a branch
 *  - rows(): streams a sheet's data rows without loading the workbook into memory
 *  - normalizeRow(): converts one row into clean member data plus errors / warnings
 *  - sideListEntries(): reads the working lists beside the main table (Acct. Number + flag/segment/balance)
 * Excel error values (#N/A, #VALUE!, ####…) and computed columns are never treated as data.
 */
class WorkbookParser
{
    /**
     * Importable fields and their labels (used by the column-mapping screen).
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'account_no' => 'Acct. Number',
        'full_name' => 'Account Name',
        'last_name' => 'Last name',
        'first_name' => 'First name',
        'middle_name' => 'Middle name',
        'suffix' => 'Suffix',
        'segment' => 'Segmentation',
        'category' => 'Category',
        'application_date' => 'Application date',
        'approval_date' => 'Approval date (NEW date)',
        'upgrade_requested_at' => 'Upgrading date',
        'savings_balance' => 'Savings balance',
        'savings_account_status' => 'Savings account status (dormant-txn / dormant-bal)',
        'status' => 'Status',
        'remarks' => 'Remarks',
        'birthdate' => 'Birthdate',
        'sex' => 'Sex',
        'contact_number' => 'Contact no.',
        'address' => 'Address',
        'date_deceased' => 'Date of death',
    ];

    /**
     * Header aliases per field, compared after lowercasing and stripping non-alphanumerics.
     *
     * @var array<string, list<string>>
     */
    public const HEADER_ALIASES = [
        'account_no' => ['acctnumber', 'acctno', 'accountnumber', 'accountno', 'accno', 'acctnum', 'accountnum', 'memberno', 'membersno', 'memberid', 'idno', 'cid', 'cidno', 'savingsacctno', 'saacctno', 'sano'],
        'full_name' => ['accountname', 'name', 'fullname', 'membername', 'membersname', 'nameofmember', 'nameofmembers', 'completename'],
        'last_name' => ['lastname', 'surname', 'familyname', 'lname'],
        'first_name' => ['firstname', 'givenname', 'fname'],
        'middle_name' => ['middlename', 'middleinitial', 'mi', 'mname'],
        'suffix' => ['suffix', 'ext', 'nameext', 'nameextension'],
        'segment' => ['segmentation', 'segment', 'membersegment', 'seg', 'classification'],
        'category' => ['category', 'colisapcategory', 'coverage', 'colisapcoverage', 'plan', 'benefitcategory'],
        'application_date' => ['applicationdate', 'dateofapplication', 'dateapplied'],
        'approval_date' => ['newdate', 'newdateof', 'approvaldate', 'dateapproved', 'dateofapproval', 'membershipdate', 'dateofmembership', 'datejoined', 'dateenrolled', 'dateofenrollment', 'enrollmentdate'],
        'upgrade_requested_at' => ['upgradingdate', 'upgradingdateof', 'upgradedate', 'dateofupgrading', 'dateofupgrade', 'upgraderequestdate'],
        'savings_balance' => ['savingsbalance', 'savingsbal', 'savbal', 'balance', 'savings', 'endingbalance', 'sabalance'],
        'savings_account_status' => ['accountstatus', 'acctstatus', 'savingsstatus', 'dormancy', 'dormancystatus', 'satatus'],
        'status' => ['status', 'memberstatus', 'colisapstatus'],
        'remarks' => ['remarks', 'remark', 'notes', 'note'],
        'birthdate' => ['birthdate', 'birthday', 'dateofbirth', 'dob', 'bday', 'bdate'],
        'sex' => ['sex', 'gender'],
        'contact_number' => ['contactno', 'contactnumber', 'mobileno', 'mobilenumber', 'cellphoneno', 'cpno', 'phone', 'phoneno', 'telno'],
        'address' => ['address', 'homeaddress', 'residence'],
        'date_deceased' => ['datedeceased', 'dateofdeath', 'datedied', 'dod', 'deathdate'],
    ];

    /**
     * Computed / working columns of the masterlist that must not be imported (recalculated by the system).
     *
     * @var list<string>
     */
    private const IGNORED_HEADER_PREFIXES = ['newstatus', 'newof', 'upgradingof', 'upgradingstatus', 'qualified', 'forupload', 'uploadstatus', 'days', 'noofdays'];

    /**
     * @var list<string>
     */
    private const IGNORED_HEADERS = ['upgrading', 'x', 'no', 'count', 'total'];

    private const HEADER_SCAN_ROWS = 25;

    public function __construct(
        private NameParser $nameParser,
        private StatusTextMapper $statusTextMapper,
    ) {}

    // ------------------------------------------------------------------ Workbook structure

    /**
     * Inspect every sheet: header row, column mapping, suggested branch and data-row count.
     *
     * @return list<array{index: int, name: string, header_row: ?int, headers: array<int, string>, columns: array<string, int>, branch_id: ?int, branch_name: ?string, data_rows: int, include: bool}>
     */
    public function detectSheets(string $path): array
    {
        $reader = $this->open($path);
        $sheets = [];

        try {
            foreach ($reader->getSheetIterator() as $index => $sheet) {
                $scanned = [];
                $count = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $count++;

                    if ($count <= self::HEADER_SCAN_ROWS) {
                        $scanned[] = $row->toArray();
                    }
                }

                [$headerIndex, $columns] = $this->detectHeader($scanned);
                $branch = Branch::resolve($sheet->getName());
                $headers = $headerIndex === null ? [] : array_map(fn ($cell) => $this->cellToString($cell), $scanned[$headerIndex]);

                $sheets[] = [
                    'index' => $index - 1,
                    'name' => $sheet->getName(),
                    'header_row' => $headerIndex === null ? null : $headerIndex + 1,
                    'headers' => array_filter($headers, fn (string $header) => $header !== ''),
                    'columns' => $columns,
                    'branch_id' => $branch?->id,
                    'branch_name' => $branch?->name,
                    'data_rows' => $headerIndex === null ? 0 : max(0, $count - $headerIndex - 1),
                    'include' => $headerIndex !== null && isset($columns['account_no']) && $branch !== null,
                ];
            }
        } finally {
            $reader->close();
        }

        return $sheets;
    }

    /**
     * Stream the data rows after a sheet's header row, keyed by 1-based spreadsheet row number.
     *
     * @return Generator<int, array<int, mixed>>
     */
    public function rows(string $path, int $sheetIndex, int $headerRow): Generator
    {
        $reader = $this->open($path);

        try {
            foreach ($reader->getSheetIterator() as $index => $sheet) {
                if ($index - 1 !== $sheetIndex) {
                    continue;
                }

                $rowNumber = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $rowNumber++;

                    if ($rowNumber > $headerRow) {
                        yield $rowNumber => $row->toArray();
                    }
                }

                break;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     * @return array{0: ?int, 1: array<string, int>}
     */
    public function detectHeader(array $rows): array
    {
        $bestIndex = null;
        $bestMap = [];

        foreach ($rows as $index => $cells) {
            $map = $this->mapColumns($cells);
            $hasIdentity = isset($map['account_no']) || isset($map['full_name']) || (isset($map['last_name'], $map['first_name']));

            if ($hasIdentity && count($map) >= 2 && count($map) > count($bestMap)) {
                $bestIndex = $index;
                $bestMap = $map;
            }
        }

        return [$bestIndex, $bestMap];
    }

    /**
     * @param  array<int, mixed>  $cells
     * @return array<string, int>
     */
    public function mapColumns(array $cells): array
    {
        $map = [];

        foreach ($cells as $column => $cell) {
            $field = $this->matchHeader($this->cellToString($cell));

            if ($field !== null && ! isset($map[$field])) {
                $map[$field] = $column;
            }
        }

        return $map;
    }

    public function matchHeader(string $header): ?string
    {
        $normalized = preg_replace('/[^a-z0-9]/', '', Str::lower($header)) ?? '';

        if ($normalized === '' || ctype_digit($normalized) || in_array($normalized, self::IGNORED_HEADERS, true)
            || Str::startsWith($normalized, self::IGNORED_HEADER_PREFIXES)
            || str_contains($normalized, 'loan') || str_contains($normalized, 'beneficiar')) {
            return null;
        }

        if (str_contains($normalized, 'lastname') && str_contains($normalized, 'firstname')) {
            return 'full_name';
        }

        // The masterlist labels dates by stage: "NEW Date of …" (approval) and "UPGRADING Date of …" (upgrade request).
        if (str_contains($normalized, 'date') && str_starts_with($normalized, 'upgrad')) {
            return 'upgrade_requested_at';
        }

        if (str_contains($normalized, 'date') && str_starts_with($normalized, 'new')) {
            return 'approval_date';
        }

        foreach (self::HEADER_ALIASES as $field => $aliases) {
            if (in_array($normalized, $aliases, true)) {
                return $field;
            }
        }

        $bestField = null;
        $bestLength = 0;

        foreach (self::HEADER_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                if (strlen($alias) >= 5 && strlen($alias) > $bestLength && str_contains($normalized, $alias)) {
                    $bestField = $field;
                    $bestLength = strlen($alias);
                }
            }
        }

        return $bestField;
    }

    // ------------------------------------------------------------------ Rows

    /**
     * Normalize one row. Missing or invalid values are reported, never guessed.
     *
     * @param  array<int, mixed>  $cells
     * @param  array<string, int>  $columns
     * @return array{blank: bool, data: array<string, mixed>, raw: array<string, string>, errors: list<string>, warnings: list<string>, numeric_account: bool}
     */
    public function normalizeRow(array $cells, array $columns, bool $allowNegativeBalance = false): array
    {
        $raw = [];

        foreach ($columns as $field => $column) {
            $raw[$field] = $this->cellToString($cells[$column] ?? null);
        }

        $raw = array_filter($raw, fn (string $value) => $value !== '');
        $result = ['blank' => false, 'data' => [], 'raw' => $raw, 'errors' => [], 'warnings' => [], 'numeric_account' => false];

        if ($result['raw'] === []) {
            $result['blank'] = true;

            return $result;
        }

        $nameText = Str::lower($raw['full_name'] ?? $raw['last_name'] ?? '');

        if ($nameText !== '' && Str::startsWith($nameText, ['total', 'grand total', 'sub-total', 'subtotal'])) {
            $result['blank'] = true;

            return $result;
        }

        $data = &$result['data'];
        $errors = &$result['errors'];
        $warnings = &$result['warnings'];

        // Acct. Number — the unique identifier, always a string.
        $accountCell = isset($columns['account_no']) ? ($cells[$columns['account_no']] ?? null) : null;
        $result['numeric_account'] = is_int($accountCell) || is_float($accountCell);
        $data['account_no'] = $this->normalizeAccountNumber($accountCell);

        if ($data['account_no'] === null) {
            $errors[] = 'Missing Acct. Number';
        } elseif (! preg_match('/^[0-9A-Za-z\-]{4,30}$/', $data['account_no'])) {
            $errors[] = "Invalid Acct. Number \"{$data['account_no']}\"";
        }

        // Names
        $data += $this->parseName($raw);

        if ($data['last_name'] === '' || $data['first_name'] === '') {
            $errors[] = 'Could not read a first and last name';
        }

        // Segmentation (separate from the benefit category)
        $data['segment'] = $this->parseSegment($raw['segment'] ?? null);

        if ($data['segment'] === null) {
            $warnings[] = isset($raw['segment']) ? "Unrecognized segmentation \"{$raw['segment']}\" — left Unassigned" : 'No segmentation — left Unassigned';
        }

        // Benefit category
        $data['category'] = $this->parseCategory($raw['category'] ?? null);

        if (isset($raw['category']) && $data['category'] === null) {
            $errors[] = "Invalid category \"{$raw['category']}\"";
        }

        // Dates
        foreach (['application_date', 'approval_date', 'upgrade_requested_at', 'birthdate', 'date_deceased'] as $field) {
            $data[$field] = $this->parseDate(isset($columns[$field]) ? ($cells[$columns[$field]] ?? null) : null);

            if (isset($raw[$field]) && $data[$field] === null) {
                $message = 'Invalid '.Str::lower(self::FIELDS[$field])." \"{$raw[$field]}\"";
                $field === 'approval_date' ? $errors[] = $message : $warnings[] = $message;
            }
        }

        // Savings balance
        $data['savings_balance'] = $this->parseAmount(isset($columns['savings_balance']) ? ($cells[$columns['savings_balance']] ?? null) : null);

        if (isset($raw['savings_balance']) && $data['savings_balance'] === null) {
            $warnings[] = "Unreadable savings balance \"{$raw['savings_balance']}\" — balance not updated";
        } elseif ($data['savings_balance'] !== null && $data['savings_balance'] < 0 && ! $allowNegativeBalance) {
            $errors[] = 'Negative savings balance '.number_format($data['savings_balance'], 2);
        }

        // Status flags
        $data['savings_account_status'] = $this->statusTextMapper->dormancyFlag($raw['savings_account_status'] ?? null)
            ?? $this->statusTextMapper->dormancyFlag($raw['status'] ?? null)
            ?? $this->statusTextMapper->dormancyFlag($raw['remarks'] ?? null);
        $data['terminal_status'] = $this->statusTextMapper->map($raw['status'] ?? null)
            ?? $this->statusTextMapper->map($raw['remarks'] ?? null);

        $data['sex'] = $this->parseSex($raw['sex'] ?? null);
        $data['contact_number'] = $raw['contact_number'] ?? null;
        $data['address'] = $raw['address'] ?? null;
        $data['remarks'] = $raw['remarks'] ?? null;

        return $result;
    }

    /**
     * Working lists beside the main table: an account number followed by a dormancy/deceased flag,
     * a segmentation letter or a balance. Returns entries keyed by account number.
     *
     * @param  array<int, mixed>  $cells
     * @param  list<int>  $skipColumns
     * @return list<array{account_no: string, account_name: ?string, kind: 'flag'|'terminal'|'segment'|'balance', value: string|float}>
     */
    public function sideListEntries(array $cells, array $skipColumns): array
    {
        $entries = [];
        $cells = array_values($cells);
        $count = count($cells);

        for ($column = 0; $column < $count; $column++) {
            if (in_array($column, $skipColumns, true)) {
                continue;
            }

            $text = $this->cellToString($cells[$column]);

            if (! preg_match('/^(\d{8,14})(?:\s+(.+))?$/', $text, $match)) {
                continue;
            }

            $accountNo = $match[1];
            $name = $match[2] ?? null;
            $offset = $column + 1;

            if ($name === null && $offset < $count && preg_match('/[A-Za-z]/', $this->cellToString($cells[$offset]))) {
                $name = $this->cellToString($cells[$offset]);
                $offset++;
            }

            if ($name === null) {
                continue;
            }

            for ($next = $offset; $next < min($count, $offset + 2); $next++) {
                $value = $cells[$next];
                $valueText = $this->cellToString($value);

                if ($valueText === '') {
                    continue;
                }

                // In the segmentation list a lone letter is a segment (D = Diamond), not a "D = deceased" remark.
                if (strlen($valueText) === 1 && ($segment = $this->parseSegment($valueText))) {
                    $entries[] = ['account_no' => $accountNo, 'account_name' => $name, 'kind' => 'segment', 'value' => $segment];
                } elseif ($flag = $this->statusTextMapper->dormancyFlag($valueText)) {
                    $entries[] = ['account_no' => $accountNo, 'account_name' => $name, 'kind' => 'flag', 'value' => $flag];
                } elseif ($terminal = $this->statusTextMapper->map($valueText)) {
                    $entries[] = ['account_no' => $accountNo, 'account_name' => $name, 'kind' => 'terminal', 'value' => $terminal];
                } elseif (($amount = $this->parseAmount($value)) !== null) {
                    $entries[] = ['account_no' => $accountNo, 'account_name' => $name, 'kind' => 'balance', 'value' => $amount];
                }

                break;
            }

            $column = $offset;
        }

        return $entries;
    }

    // ------------------------------------------------------------------ Value parsing

    public function normalizeAccountNumber(mixed $value): ?string
    {
        if (is_float($value) || is_int($value)) {
            return $value > 0 ? number_format((float) $value, 0, '', '') : null;
        }

        $text = preg_replace('/\s+/', '', $this->cellToString($value)) ?? '';
        $text = ltrim($text, "'");

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, string>  $raw
     * @return array{account_name: ?string, first_name: string, middle_name: ?string, last_name: string, suffix: ?string}
     */
    private function parseName(array $raw): array
    {
        $name = ['first_name' => '', 'middle_name' => null, 'last_name' => '', 'suffix' => null];

        if (isset($raw['full_name'])) {
            $name = $this->nameParser->parse($raw['full_name']);
        }

        if (isset($raw['last_name'])) {
            [$name['last_name'], $suffix] = $this->nameParser->splitSuffix($raw['last_name']);
            $name['suffix'] ??= $suffix;
        }

        if (isset($raw['first_name'])) {
            [$name['first_name'], $suffix] = $this->nameParser->splitSuffix($raw['first_name']);
            $name['suffix'] ??= $suffix;
        }

        if (isset($raw['middle_name'])) {
            $middleName = (string) $this->nameParser->titleCase($raw['middle_name']);
            $name['middle_name'] = mb_strlen($middleName) === 1 ? $middleName.'.' : $middleName;
        }

        if (isset($raw['suffix'])) {
            $name['suffix'] = $this->nameParser->normalizeSuffix($raw['suffix']);
        }

        $accountName = $raw['full_name'] ?? trim(($raw['last_name'] ?? '').', '.($raw['first_name'] ?? '').' '.($raw['middle_name'] ?? ''), ' ,');

        return ['account_name' => $accountName !== '' ? mb_strtoupper(preg_replace('/\s+/', ' ', $accountName)) : null, ...$name];
    }

    public function parseSegment(?string $value): ?string
    {
        $key = Str::upper(trim((string) $value));

        return match (true) {
            $key === 'D' || str_starts_with($key, 'DIAMOND') => 'D',
            $key === 'G' || str_starts_with($key, 'GOLD') => 'G',
            $key === 'S' || str_starts_with($key, 'SILVER') => 'S',
            $key === 'R' || str_starts_with($key, 'REGULAR') => 'R',
            default => null,
        };
    }

    /**
     * "COLISAP40000", "40,000", "40K", "P60,000" → '40000' / '60000'.
     */
    public function parseCategory(mixed $value): ?string
    {
        $text = Str::upper($this->cellToString($value));

        if ($text === '') {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $text) ?? '';

        return match (true) {
            str_starts_with($digits, '60') => '60000',
            str_starts_with($digits, '40') => '40000',
            default => null,
        };
    }

    public function parseDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (is_int($value) || is_float($value)) {
            return $value > 0 && $value < 100000 ? Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString() : null;
        }

        $text = $this->cellToString($value);

        if ($text === '' || in_array(Str::lower($text), ['-', 'n/a', 'na', 'none'], true)) {
            return null;
        }

        if (is_numeric($text)) {
            return $this->parseDate((float) $text);
        }

        foreach (['m/d/Y', 'm/d/y', 'n/j/Y', 'n/j/y', 'm-d-Y', 'Y-m-d', 'd-M-Y', 'd-M-y', 'M d, Y', 'F d, Y', 'M. d, Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $text);

                if ($date !== null && $date->format($format) === $text) {
                    return $date->toDateString();
                }
            } catch (Throwable) {
                // Try the next format.
            }
        }

        try {
            return Carbon::parse($text)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    public function parseAmount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        $text = $this->cellToString($value);

        if ($text === '') {
            return null;
        }

        if (in_array($text, ['-', '–'], true)) {
            return 0.0;
        }

        $isNegative = (str_starts_with($text, '(') && str_ends_with($text, ')')) || str_starts_with($text, '-');
        $number = preg_replace('/[^0-9.]/', '', $text) ?? '';

        if ($number === '' || ! is_numeric($number)) {
            return null;
        }

        return round((float) $number * ($isNegative ? -1 : 1), 2);
    }

    private function parseSex(?string $value): ?string
    {
        return match (Str::lower(substr((string) $value, 0, 1))) {
            'm' => 'male',
            'f' => 'female',
            default => null,
        };
    }

    /**
     * Cell value as trimmed text; Excel error values become empty.
     */
    public function cellToString(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => number_format($value, 0, '.', ''),
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_object($value) && ! method_exists($value, '__toString') => '',
            default => trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''),
        };

        return self::isExcelArtifact($text) ? '' : $text;
    }

    public static function isExcelArtifact(string $text): bool
    {
        return (bool) preg_match('/^#+$|^#(N\/A|VALUE!|REF!|DIV\/0!|NAME\?|NUM!|NULL!|SPILL!|CALC!|GETTING_DATA)$/i', $text);
    }

    /**
     * Open the workbook keeping empty rows, so reported row numbers match the Excel row numbers.
     */
    private function open(string $path): ReaderInterface
    {
        $reader = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'ods' => new ODSReader(tap(new ODSOptions, fn (ODSOptions $options) => $options->SHOULD_PRESERVE_EMPTY_ROWS = true)),
            'csv', 'txt' => new CSVReader(tap(new CSVOptions, fn (CSVOptions $options) => $options->SHOULD_PRESERVE_EMPTY_ROWS = true)),
            default => new XLSXReader(tap(new XLSXOptions, fn (XLSXOptions $options) => $options->SHOULD_PRESERVE_EMPTY_ROWS = true)),
        };

        $reader->open($path);

        return $reader;
    }
}
