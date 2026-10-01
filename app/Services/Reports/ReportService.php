<?php

namespace App\Services\Reports;

use App\Models\Branch;
use App\Models\Claim;
use App\Models\Contribution;
use App\Models\ImportBatch;
use App\Models\Member;
use App\Models\MemberHistory;
use App\Models\ReplenishmentNotice;
use App\Services\Colisap\MonitoringService;
use App\Services\Policy\PolicySettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every COLISAP report, built from live database queries with common filters
 * (branch, status, category, segment, date range) and exported to Excel/CSV/PDF.
 */
class ReportService
{
    /**
     * PDF rendering is memory-heavy; larger reports should be exported to Excel.
     */
    public const PDF_ROW_LIMIT = 3000;

    /**
     * @var array<string, array{label: string, group: string, date: ?string}>
     */
    public const REPORTS = [
        'masterlist' => ['label' => 'Member masterlist', 'group' => 'Members', 'date' => 'Approval date'],
        'status_active' => ['label' => 'Active members', 'group' => 'Members by status', 'date' => 'Approval date'],
        'status_waiting' => ['label' => 'Waiting members', 'group' => 'Members by status', 'date' => 'Approval date'],
        'status_dormant' => ['label' => 'Dormant members', 'group' => 'Members by status', 'date' => 'Approval date'],
        'status_deceased' => ['label' => 'Deceased members', 'group' => 'Members by status', 'date' => 'Date of death'],
        'status_terminated' => ['label' => 'Terminated members', 'group' => 'Members by status', 'date' => 'Termination date'],
        'status_withdrawn' => ['label' => 'Withdrawn members', 'group' => 'Members by status', 'date' => 'Withdrawal date'],
        'category_40000' => ['label' => '40K members', 'group' => 'Members by category', 'date' => 'Approval date'],
        'category_60000' => ['label' => '60K members', 'group' => 'Members by category', 'date' => 'Approval date'],
        'segment_D' => ['label' => 'Diamond members', 'group' => 'Members by segment', 'date' => 'Approval date'],
        'segment_G' => ['label' => 'Gold members', 'group' => 'Members by segment', 'date' => 'Approval date'],
        'segment_S' => ['label' => 'Silver members', 'group' => 'Members by segment', 'date' => 'Approval date'],
        'segment_R' => ['label' => 'Regular members', 'group' => 'Members by segment', 'date' => 'Approval date'],
        'branch_summary' => ['label' => 'Branch summary', 'group' => 'Monitoring', 'date' => null],
        'beneficiary_compliance' => ['label' => 'Beneficiary compliance', 'group' => 'Monitoring', 'date' => null],
        'effectivity' => ['label' => '180-day effectivity', 'group' => 'Monitoring', 'date' => 'Effectivity date'],
        'upgrades' => ['label' => '90-day upgrade eligibility', 'group' => 'Monitoring', 'date' => 'Upgrade request date'],
        'replenishment' => ['label' => 'Savings replenishment', 'group' => 'Monitoring', 'date' => 'Notice date'],
        'claims' => ['label' => 'Mortuary claims', 'group' => 'Mortuary', 'date' => 'Date filed'],
        'contributions' => ['label' => 'Contributions', 'group' => 'Mortuary', 'date' => 'Contribution date'],
        'status_history' => ['label' => 'Member status history', 'group' => 'History', 'date' => 'Change date'],
        'import_history' => ['label' => 'Excel import history', 'group' => 'History', 'date' => 'Upload date'],
    ];

    public function __construct(
        private PolicySettings $policy,
        private SpreadsheetWriter $writer,
    ) {}

    /**
     * @return array<string, array<string, string>> grouped options for a select
     */
    public static function options(): array
    {
        return collect(self::REPORTS)->groupBy('group', preserveKeys: true)
            ->map(fn ($reports) => $reports->map(fn ($report) => $report['label'])->all())
            ->all();
    }

    /**
     * @param  array{branch_ids?: list<int|string>, statuses?: list<string>, category?: ?string, segment?: ?string, from?: ?string, until?: ?string}  $filters
     * @return array{title: string, headings: list<string>, rows: \Generator<list<mixed>>|array<int, list<mixed>>, count: int, text_columns: list<int>, filters: list<string>}
     */
    public function build(string $report, array $filters = []): array
    {
        $definition = self::REPORTS[$report] ?? self::REPORTS['masterlist'];

        $result = match (true) {
            $report === 'branch_summary' => $this->branchSummary($filters),
            $report === 'beneficiary_compliance' => $this->beneficiaryCompliance($filters),
            $report === 'effectivity' => $this->effectivity($filters),
            $report === 'upgrades' => $this->upgrades($filters),
            $report === 'replenishment' => $this->replenishment($filters),
            $report === 'claims' => $this->claims($filters),
            $report === 'contributions' => $this->contributions($filters),
            $report === 'status_history' => $this->statusHistory($filters),
            $report === 'import_history' => $this->importHistory($filters),
            default => $this->memberList($report, $filters),
        };

        return [...$result, 'title' => $definition['label'], 'filters' => $this->describeFilters($filters, $definition['date'])];
    }

    public function download(string $report, string $format, array $filters = []): StreamedResponse
    {
        $built = $this->build($report, $filters);
        $baseName = str($built['title'])->slug().'-'.now()->format('Ymd-His');

        if ($format === 'pdf') {
            $rows = collect($built['rows'])->take(self::PDF_ROW_LIMIT)->all();

            $pdf = Pdf::loadView('reports.generic', [
                'title' => $built['title'],
                'filters' => $built['filters'],
                'headings' => $built['headings'],
                'rows' => $rows,
                'total' => $built['count'],
                'truncated' => $built['count'] > self::PDF_ROW_LIMIT,
                'generatedAt' => now(),
                'logo' => $this->logoDataUri(),
            ])->setPaper('a4', count($built['headings']) > 6 ? 'landscape' : 'portrait');

            return response()->streamDownload(fn () => print ($pdf->output()), "{$baseName}.pdf", ['Content-Type' => 'application/pdf']);
        }

        return $this->writer->download($baseName, $format, $built['headings'], $built['rows'], $built['text_columns'], [
            'Barbaza Multi-Purpose Cooperative — COLISAP',
            $built['title'],
            ...$built['filters'],
            'Generated '.now()->format('M j, Y g:i A'),
        ]);
    }

    public function logoDataUri(): ?string
    {
        $path = public_path('images/logo-192.png');

        return is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : null;
    }

    // ------------------------------------------------------------------ Filters

    /**
     * @param  Builder<Member>  $query
     */
    private function applyMemberFilters(Builder $query, array $filters, ?string $dateColumn = 'approval_date'): Builder
    {
        return $query
            ->when($filters['branch_ids'] ?? [], fn (Builder $q, array $ids) => $q->whereIn('members.branch_id', $ids))
            ->when($filters['statuses'] ?? [], fn (Builder $q, array $statuses) => $q->whereIn('members.status', $statuses))
            ->when($filters['category'] ?? null, fn (Builder $q, string $category) => $q->where('members.category', $category))
            ->when($filters['segment'] ?? null, fn (Builder $q, string $segment) => $segment === 'none' ? $q->whereNull('members.segment') : $q->where('members.segment', $segment))
            ->when($dateColumn && ($filters['from'] ?? null), fn (Builder $q) => $q->whereDate($dateColumn, '>=', $filters['from']))
            ->when($dateColumn && ($filters['until'] ?? null), fn (Builder $q) => $q->whereDate($dateColumn, '<=', $filters['until']));
    }

    /**
     * @return list<string>
     */
    private function describeFilters(array $filters, ?string $dateLabel): array
    {
        $lines = [];

        if ($ids = $filters['branch_ids'] ?? []) {
            $lines[] = 'Branch: '.Branch::whereIn('id', $ids)->orderBy('sort_order')->pluck('name')->implode(', ');
        }

        if ($statuses = $filters['statuses'] ?? []) {
            $lines[] = 'Status: '.collect($statuses)->map(fn ($status) => Member::STATUSES[$status] ?? $status)->implode(', ');
        }

        if ($category = $filters['category'] ?? null) {
            $lines[] = 'Category: '.Member::categoryLabel($category);
        }

        if ($segment = $filters['segment'] ?? null) {
            $lines[] = 'Segment: '.($segment === 'none' ? 'Unassigned' : Member::segmentLabel($segment));
        }

        if ($dateLabel && (($filters['from'] ?? null) || ($filters['until'] ?? null))) {
            $lines[] = $dateLabel.': '.($filters['from'] ?? '…').' to '.($filters['until'] ?? '…');
        }

        return $lines;
    }

    // ------------------------------------------------------------------ Member lists

    private function memberList(string $report, array $filters): array
    {
        $dateColumn = match ($report) {
            'status_deceased' => 'date_deceased',
            'status_terminated' => 'terminated_at',
            'status_withdrawn' => 'withdrawn_at',
            default => 'approval_date',
        };

        $query = Member::query()->with('branch')->withCount('activeBeneficiaries');

        if (str_starts_with($report, 'status_')) {
            $filters['statuses'] = [substr($report, 7)];
        } elseif (str_starts_with($report, 'category_')) {
            $filters['category'] = substr($report, 9);
        } elseif (str_starts_with($report, 'segment_')) {
            $filters['segment'] = substr($report, 8);
        }

        $this->applyMemberFilters($query, $filters, $dateColumn)->orderBy('branch_id')->orderBy('account_name');

        $count = (clone $query)->count();

        return [
            'headings' => ['Acct. Number', 'Account Name', 'Branch', 'Segment', 'Category', 'Status', 'Savings Balance', 'Beneficiaries', 'Application Date', 'Approval Date', 'Effectivity Date', 'Last Activity', 'Remarks'],
            'rows' => (function () use ($query) {
                foreach ($query->lazy(500) as $member) {
                    yield [
                        $member->account_no,
                        $member->account_name,
                        $member->branch?->name,
                        Member::segmentLabel($member->segment),
                        Member::categoryLabel($member->category),
                        Member::STATUSES[$member->status] ?? $member->status,
                        round((float) $member->savings_balance, 2),
                        $member->active_beneficiaries_count,
                        $member->application_date?->toDateString(),
                        $member->approval_date?->toDateString(),
                        $member->effectivityDate()?->toDateString(),
                        $member->last_activity_date?->toDateString(),
                        $member->termination_reason ?? $member->withdrawal_reason ?? ($member->date_deceased ? 'Died '.$member->date_deceased->toDateString() : null),
                    ];
                }
            })(),
            'count' => $count,
            'text_columns' => [0],
        ];
    }

    // ------------------------------------------------------------------ Monitoring reports

    private function branchSummary(array $filters): array
    {
        $byBranch = app(MonitoringService::class)->statusByBranch();
        $selected = $filters['branch_ids'] ?? [];
        $names = $selected ? Branch::whereIn('id', $selected)->pluck('name')->all() : null;
        $rows = [];

        foreach ($byBranch as $branch => $counts) {
            if ($names !== null && ! in_array($branch, $names, true)) {
                continue;
            }

            $rows[] = [$branch, ...array_values($counts), array_sum($counts)];
        }

        $totals = ['Total'];

        foreach (array_keys(Member::STATUSES) as $index => $status) {
            $totals[] = array_sum(array_column($rows, $index + 1));
        }

        $totals[] = array_sum(array_column($rows, count(Member::STATUSES) + 1));
        $rows[] = $totals;

        return [
            'headings' => ['Branch', ...array_values(Member::STATUSES), 'Total'],
            'rows' => $rows,
            'count' => count($rows),
            'text_columns' => [],
        ];
    }

    private function beneficiaryCompliance(array $filters): array
    {
        $query = Member::query()->with('branch')->participating()->withCount('activeBeneficiaries');
        $this->applyMemberFilters($query, $filters, null)->orderBy('active_beneficiaries_count')->orderBy('account_name');
        $min = $this->policy->int('min_beneficiaries');
        $max = $this->policy->int('max_beneficiaries');

        return [
            'headings' => ['Acct. Number', 'Account Name', 'Branch', 'Status', 'Beneficiaries', 'Total share %', 'Compliance'],
            'rows' => (function () use ($query, $min, $max) {
                foreach ($query->lazy(500) as $member) {
                    $count = $member->active_beneficiaries_count;
                    $share = (float) $member->activeBeneficiaries()->sum('share_percentage');

                    yield [
                        $member->account_no,
                        $member->account_name,
                        $member->branch?->name,
                        Member::STATUSES[$member->status] ?? $member->status,
                        $count,
                        $count ? round($share, 2) : null,
                        match (true) {
                            $count === 0 => 'Beneficiary information incomplete',
                            $count < $min || $count > $max => 'Outside allowed range',
                            round($share, 2) !== 100.0 => 'Shares do not total 100%',
                            default => 'Compliant',
                        },
                    ];
                }
            })(),
            'count' => (clone $query)->count(),
            'text_columns' => [0],
        ];
    }

    private function effectivity(array $filters): array
    {
        $days = $this->policy->int('effectivity_days');
        $query = Member::query()->with('branch')->where('status', 'waiting');
        $this->applyMemberFilters($query, array_diff_key($filters, ['from' => 1, 'until' => 1, 'statuses' => 1]), null)
            ->when($filters['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('approval_date', '>=', Carbon::parse($date)->subDays($days)))
            ->when($filters['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('approval_date', '<=', Carbon::parse($date)->subDays($days)))
            ->orderBy('approval_date');

        return [
            'headings' => ['Acct. Number', 'Account Name', 'Branch', 'Category', 'Approval Date', 'Effectivity Date', 'Days Remaining'],
            'rows' => (function () use ($query) {
                foreach ($query->lazy(500) as $member) {
                    yield [
                        $member->account_no,
                        $member->account_name,
                        $member->branch?->name,
                        Member::categoryLabel($member->category),
                        $member->approval_date?->toDateString(),
                        $member->effectivityDate()?->toDateString(),
                        $member->daysUntilEffective(),
                    ];
                }
            })(),
            'count' => (clone $query)->count(),
            'text_columns' => [0],
        ];
    }

    private function upgrades(array $filters): array
    {
        $query = Member::query()->with('branch')->participating()->where('category', '40000')->whereNotNull('category_upgrade_requested_at');
        $this->applyMemberFilters($query, array_diff_key($filters, ['category' => 1]), 'category_upgrade_requested_at')->orderBy('category_upgrade_requested_at');
        $min60 = $this->policy->decimal('min_balance_60k');

        return [
            'headings' => ['Acct. Number', 'Account Name', 'Branch', 'Request Date', 'Eligible Date', 'Upgrade Status', 'Savings Balance', 'Meets 60K Balance'],
            'rows' => (function () use ($query, $min60) {
                foreach ($query->lazy(500) as $member) {
                    yield [
                        $member->account_no,
                        $member->account_name,
                        $member->branch?->name,
                        $member->category_upgrade_requested_at?->toDateString(),
                        $member->upgradeEligibleDate()?->toDateString(),
                        ucfirst($member->upgradeStatus()),
                        round((float) $member->savings_balance, 2),
                        (float) $member->savings_balance >= $min60 ? 'Yes' : 'No',
                    ];
                }
            })(),
            'count' => (clone $query)->count(),
            'text_columns' => [0],
        ];
    }

    private function replenishment(array $filters): array
    {
        $query = ReplenishmentNotice::query()->with('member.branch')
            ->whereHas('member', fn (Builder $q) => $this->applyMemberFilters($q, $filters, null))
            ->when($filters['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('notice_date', '>=', $date))
            ->when($filters['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('notice_date', '<=', $date))
            ->orderBy('deadline');

        return [
            'headings' => ['Acct. Number', 'Account Name', 'Branch', 'Category', 'Required', 'Balance at Notice', 'Shortfall', 'Notice Date', 'Deadline', 'Status', 'Resolved'],
            'rows' => (function () use ($query) {
                foreach ($query->lazy(500) as $notice) {
                    yield [
                        $notice->member?->account_no,
                        $notice->member?->account_name,
                        $notice->member?->branch?->name,
                        Member::categoryLabel($notice->category),
                        (float) $notice->required_balance,
                        (float) $notice->balance_at_notice,
                        (float) $notice->shortfall,
                        $notice->notice_date?->toDateString(),
                        $notice->deadline?->toDateString(),
                        ReplenishmentNotice::STATUSES[$notice->status] ?? $notice->status,
                        $notice->resolved_at?->toDateString(),
                    ];
                }
            })(),
            'count' => (clone $query)->count(),
            'text_columns' => [0],
        ];
    }

    // ------------------------------------------------------------------ Mortuary

    private function claims(array $filters): array
    {
        $query = Claim::query()->with(['member.branch', 'beneficiary'])
            ->whereHas('member', fn (Builder $q) => $this->applyMemberFilters($q, array_diff_key($filters, ['statuses' => 1, 'category' => 1]), null))
            ->when($filters['category'] ?? null, fn (Builder $q, $category) => $q->where('category', $category))
            ->when($filters['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('date_filed', '>=', $date))
            ->when($filters['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('date_filed', '<=', $date))
            ->orderBy('date_filed');

        return [
            'headings' => ['Claim No.', 'Acct. Number', 'Member', 'Branch', 'Category', 'Date of Death', 'Date Filed', 'Gross Benefit', 'Loans', 'Other Obligations', 'Net Benefit', 'Status', 'Settled'],
            'rows' => (function () use ($query) {
                foreach ($query->lazy(500) as $claim) {
                    yield [
                        $claim->claim_no,
                        $claim->member?->account_no,
                        $claim->member?->account_name,
                        $claim->member?->branch?->name,
                        Member::categoryLabel($claim->category),
                        $claim->date_of_death?->toDateString(),
                        $claim->date_filed?->toDateString(),
                        (float) $claim->gross_benefit,
                        (float) $claim->outstanding_loan,
                        (float) $claim->other_obligations,
                        (float) $claim->net_benefit,
                        Claim::STATUSES[$claim->status] ?? $claim->status,
                        $claim->settled_at?->toDateString(),
                    ];
                }
            })(),
            'count' => (clone $query)->count(),
            'text_columns' => [1],
        ];
    }

    private function contributions(array $filters): array
    {
        $query = Contribution::query()->with(['member.branch', 'claim'])
            ->whereHas('member', fn (Builder $q) => $this->applyMemberFilters($q, array_diff_key($filters, ['segment' => 1]), null))
            ->when($filters['segment'] ?? null, fn (Builder $q, $segment) => $q->where('segment', $segment))
            ->when($filters['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('contribution_date', '>=', $date))
            ->when($filters['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('contribution_date', '<=', $date))
            ->orderBy('contribution_date');

        return [
            'headings' => ['Date', 'Claim No.', 'Acct. Number', 'Member', 'Branch', 'Segment', 'Amount', 'Member Share', 'Coop Share', 'Status'],
            'rows' => (function () use ($query) {
                foreach ($query->lazy(500) as $contribution) {
                    yield [
                        $contribution->contribution_date?->toDateString(),
                        $contribution->claim?->claim_no,
                        $contribution->member?->account_no,
                        $contribution->member?->account_name,
                        $contribution->member?->branch?->name,
                        Member::segmentLabel($contribution->segment),
                        (float) $contribution->amount,
                        (float) $contribution->member_share,
                        (float) $contribution->coop_share,
                        Contribution::STATUSES[$contribution->status] ?? $contribution->status,
                    ];
                }
            })(),
            'count' => (clone $query)->count(),
            'text_columns' => [2],
        ];
    }

    // ------------------------------------------------------------------ History

    private function statusHistory(array $filters): array
    {
        $query = MemberHistory::query()->with(['member.branch', 'changer'])
            ->whereHas('member', fn (Builder $q) => $this->applyMemberFilters($q, array_diff_key($filters, ['statuses' => 1]), null))
            ->when($filters['statuses'] ?? [], fn (Builder $q, $statuses) => $q->where('field', 'status')->whereIn('new_value', $statuses))
            ->when($filters['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('changed_at', '>=', $date))
            ->when($filters['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('changed_at', '<=', $date))
            ->orderBy('changed_at');

        return [
            'headings' => ['Date', 'Acct. Number', 'Member', 'Branch', 'Field', 'From', 'To', 'Reason', 'Source', 'By'],
            'rows' => (function () use ($query) {
                foreach ($query->lazy(500) as $history) {
                    yield [
                        $history->changed_at?->format('Y-m-d H:i'),
                        $history->member?->account_no,
                        $history->member?->account_name,
                        $history->member?->branch?->name,
                        ucfirst($history->field),
                        $history->displayValue($history->old_value),
                        $history->displayValue($history->new_value),
                        $history->reason,
                        $history->source,
                        $history->changer?->name ?? 'System',
                    ];
                }
            })(),
            'count' => (clone $query)->count(),
            'text_columns' => [1],
        ];
    }

    private function importHistory(array $filters): array
    {
        $query = ImportBatch::query()->with('uploader')
            ->when($filters['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))
            ->orderBy('id');

        return [
            'headings' => ['Import ID', 'File', 'Uploaded', 'Uploaded By', 'As Of', 'Total Rows', 'New', 'Updated', 'Duplicates', 'Invalid', 'Status', 'Branches'],
            'rows' => (function () use ($query) {
                foreach ($query->lazy(200) as $batch) {
                    yield [
                        $batch->id,
                        $batch->file_name,
                        $batch->created_at?->format('Y-m-d H:i'),
                        $batch->uploader?->name,
                        $batch->as_of_date?->toDateString(),
                        $batch->total_rows,
                        $batch->created_count,
                        $batch->updated_count,
                        $batch->duplicate_rows,
                        $batch->invalid_rows,
                        ImportBatch::STATUSES[$batch->status] ?? $batch->status,
                        implode(', ', $batch->branchNames()),
                    ];
                }
            })(),
            'count' => (clone $query)->count(),
            'text_columns' => [],
        ];
    }
}
