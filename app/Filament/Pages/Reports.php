<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\Member;
use App\Services\Reports\ReportService;
use App\Services\Reports\SpreadsheetWriter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report builder: pick a report, filter by branch / status / category / segment / dates, export to Excel, CSV or PDF.
 */
class Reports extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Data & Reports';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.reports';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->can('reports.view') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(['report' => 'masterlist', 'format' => 'xlsx']);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
            ->schema([
                Forms\Components\Select::make('report')
                    ->options(ReportService::options())
                    ->required()
                    ->live()
                    ->columnSpan(['md' => 2, 'xl' => 1]),
                Forms\Components\Select::make('branch_ids')
                    ->label('Branch')
                    ->options(fn () => Branch::options())
                    ->multiple()
                    ->placeholder('All branches'),
                Forms\Components\Select::make('statuses')
                    ->label('Status')
                    ->options(Member::STATUSES)
                    ->multiple()
                    ->placeholder('All statuses')
                    ->hidden(fn (Forms\Get $get) => str_starts_with((string) $get('report'), 'status_') || in_array($get('report'), ['effectivity', 'branch_summary', 'import_history'], true)),
                Forms\Components\Select::make('category')
                    ->options(Member::CATEGORIES)
                    ->placeholder('All categories')
                    ->hidden(fn (Forms\Get $get) => str_starts_with((string) $get('report'), 'category_') || in_array($get('report'), ['upgrades', 'branch_summary', 'import_history'], true)),
                Forms\Components\Select::make('segment')
                    ->label('Segmentation')
                    ->options([...Member::SEGMENTS, 'none' => 'Unassigned'])
                    ->placeholder('All segments')
                    ->hidden(fn (Forms\Get $get) => str_starts_with((string) $get('report'), 'segment_') || in_array($get('report'), ['branch_summary', 'import_history'], true)),
                Forms\Components\DatePicker::make('from')
                    ->label(fn (Forms\Get $get) => (ReportService::REPORTS[$get('report')]['date'] ?? 'Date').' from')
                    ->hidden(fn (Forms\Get $get) => (ReportService::REPORTS[$get('report')]['date'] ?? null) === null),
                Forms\Components\DatePicker::make('until')
                    ->label(fn (Forms\Get $get) => (ReportService::REPORTS[$get('report')]['date'] ?? 'Date').' until')
                    ->hidden(fn (Forms\Get $get) => (ReportService::REPORTS[$get('report')]['date'] ?? null) === null),
            ]);
    }

    public function export(string $format): StreamedResponse
    {
        $data = $this->form->getState();
        $format = in_array($format, ['pdf', ...array_keys(SpreadsheetWriter::FORMATS)], true) ? $format : 'xlsx';

        return app(ReportService::class)->download($data['report'], $format, [
            'branch_ids' => array_map('intval', $data['branch_ids'] ?? []),
            'statuses' => $data['statuses'] ?? [],
            'category' => $data['category'] ?? null,
            'segment' => $data['segment'] ?? null,
            'from' => $data['from'] ?? null,
            'until' => $data['until'] ?? null,
        ]);
    }
}
