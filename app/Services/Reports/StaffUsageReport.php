<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\Access\Permissions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Who is actually using the system: sign-ins, days used and the work each staff account did in a period.
 * Everything is counted from the audit log and the import history, so nothing here can be typed in by hand.
 * It is written for management, so every figure also comes as a plain sentence.
 */
class StaffUsageReport
{
    /**
     * @var array<string, string>
     */
    public const PERIODS = [
        'today' => 'Today',
        'this_week' => 'This week',
        'last_week' => 'Last week',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_year' => 'This year',
    ];

    /**
     * @var array<string, array{label: string, meaning: string, color: string, icon: string}>
     */
    public const STATUSES = [
        'using' => ['label' => 'Using the system', 'meaning' => 'Did real work: added, edited, uploaded, sent or downloaded something.', 'color' => 'success', 'icon' => 'heroicon-m-check-circle'],
        'signed_in' => ['label' => 'Signed in only', 'meaning' => 'Logged in, but did not do any work.', 'color' => 'warning', 'icon' => 'heroicon-m-eye'],
        'none' => ['label' => 'Not using the system', 'meaning' => 'No sign-in and no work recorded.', 'color' => 'danger', 'icon' => 'heroicon-m-x-circle'],
        'deactivated' => ['label' => 'Deactivated', 'meaning' => 'Account is switched off and is not counted.', 'color' => 'gray', 'icon' => 'heroicon-m-no-symbol'],
    ];

    /**
     * The day-by-day strip is only drawn while it stays readable.
     */
    private const MAX_DAYS_SHOWN = 31;

    /**
     * @return array{from: Carbon, until: Carbon, label: string}
     */
    public function range(string $period): array
    {
        $now = Carbon::now();

        [$from, $until] = match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'last_week' => [$now->copy()->subWeek()->startOfWeek(Carbon::MONDAY), $now->copy()->subWeek()->endOfWeek(Carbon::SUNDAY)],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => [$now->copy()->startOfWeek(Carbon::MONDAY), $now->copy()->endOfWeek(Carbon::SUNDAY)],
        };

        $until = $until->min($now);

        return [
            'from' => $from,
            'until' => $until,
            'label' => $from->isSameDay($until)
                ? $from->format('l, M j, Y')
                : $from->format($from->isSameYear($until) ? 'M j' : 'M j, Y').' – '.$until->format('M j, Y'),
        ];
    }

    /**
     * @return array{
     *     period: string, period_label: string, range_label: string, from: Carbon, until: Carbon,
     *     summary: array<string, int>, groups: array<string, list<string>>, days: list<Carbon>, period_days: int,
     *     staff: array<int, array<string, mixed>>, tracking_since: ?Carbon
     * }
     */
    public function build(string $period): array
    {
        $period = array_key_exists($period, self::PERIODS) ? $period : 'this_week';
        ['from' => $from, 'until' => $until, 'label' => $rangeLabel] = $this->range($period);

        $activity = DB::table('audit_logs')
            ->whereNotNull('user_id')
            ->whereBetween('created_at', [$from, $until])
            ->selectRaw('user_id, action, module, date(created_at) as day, count(*) as total')
            ->groupByRaw('user_id, action, module, date(created_at)')
            ->get()
            ->groupBy('user_id');

        $imports = DB::table('import_batches')
            ->whereNotNull('uploaded_by')
            ->whereBetween('created_at', [$from, $until])
            ->selectRaw('uploaded_by, count(*) as files, sum(created_count + updated_count) as records')
            ->groupBy('uploaded_by')
            ->get()
            ->keyBy('uploaded_by');

        $lastActive = DB::table('audit_logs')->whereNotNull('user_id')
            ->selectRaw('user_id, max(created_at) as last_active')->groupBy('user_id')->pluck('last_active', 'user_id');

        $periodDays = (int) $from->copy()->startOfDay()->diffInDays($until->copy()->startOfDay()) + 1;
        $staff = [];

        foreach (User::query()->where('in_usage_report', true)->with(['roles', 'branch'])->orderBy('name')->get() as $user) {
            $rows = $activity[$user->id] ?? collect();
            $count = fn (callable $filter): int => (int) $rows->filter($filter)->sum('total');

            $row = [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->roles->map(fn ($role) => Permissions::ROLE_LABELS[$role->name] ?? $role->name)->implode(', ') ?: 'No role',
                'branch' => $user->branch?->name ?? 'All branches',
                'sign_ins' => $count(fn ($entry) => $entry->action === 'login'),
                'active_days' => $rows->pluck('day')->map(fn ($day) => (string) $day)->unique()->values()->all(),
                'members_added' => $count(fn ($entry) => $entry->module === 'Member' && $entry->action === 'created'),
                'members_edited' => $count(fn ($entry) => $entry->module === 'Member' && in_array($entry->action, ['updated', 'deleted', 'restored'], true)),
                'files_imported' => (int) ($imports[$user->id]->files ?? 0),
                'records_imported' => (int) ($imports[$user->id]->records ?? 0),
                'sms_sent' => $count(fn ($entry) => $entry->action === 'sms_sent'),
                'reports_exported' => $count(fn ($entry) => in_array($entry->action, ['report_exported', 'members_exported'], true)),
                'last_active' => isset($lastActive[$user->id]) ? Carbon::parse($lastActive[$user->id]) : null,
            ];

            $row['days_active'] = count($row['active_days']);
            $didWork = $count(fn ($entry) => $entry->action !== 'login') > 0 || $row['files_imported'] > 0;

            $row['status'] = match (true) {
                $didWork => 'using',
                $row['sign_ins'] > 0 => 'signed_in',
                ! $user->is_active => 'deactivated',
                default => 'none',
            };
            $row['did'] = self::describe($row);

            $staff[$user->id] = $row;
        }

        // The accounts that need attention come first, then the busiest ones.
        $order = ['none' => 0, 'signed_in' => 1, 'using' => 2, 'deactivated' => 3];
        uasort($staff, fn (array $a, array $b) => [$order[$a['status']], -$a['days_active'], $a['name']] <=> [$order[$b['status']], -$b['days_active'], $b['name']]);

        $accounts = collect($staff)->where('status', '<>', 'deactivated');
        $firstSignIn = DB::table('audit_logs')->where('action', 'login')->min('created_at');

        return [
            'period' => $period,
            'period_label' => self::PERIODS[$period],
            'range_label' => $rangeLabel,
            'from' => $from,
            'until' => $until,
            'summary' => [
                'accounts' => $accounts->count(),
                'using' => $accounts->where('status', 'using')->count(),
                'signed_in_only' => $accounts->where('status', 'signed_in')->count(),
                'no_activity' => $accounts->where('status', 'none')->count(),
                'sign_ins' => (int) $accounts->sum('sign_ins'),
                'members_added' => (int) $accounts->sum('members_added'),
                'members_edited' => (int) $accounts->sum('members_edited'),
                'files_imported' => (int) $accounts->sum('files_imported'),
                'records_imported' => (int) $accounts->sum('records_imported'),
                'sms_sent' => (int) $accounts->sum('sms_sent'),
                'reports_exported' => (int) $accounts->sum('reports_exported'),
            ],
            'groups' => collect(['using', 'signed_in', 'none'])
                ->mapWithKeys(fn (string $status) => [$status => $accounts->where('status', $status)->sortBy('name')->pluck('name')->values()->all()])
                ->all(),
            'days' => $periodDays > self::MAX_DAYS_SHOWN
                ? []
                : collect(range(0, $periodDays - 1))->map(fn (int $offset) => $from->copy()->startOfDay()->addDays($offset))->all(),
            'period_days' => $periodDays,
            'staff' => $staff,
            'tracking_since' => $firstSignIn ? Carbon::parse($firstSignIn) : null,
        ];
    }

    /**
     * One plain sentence a reader can take away without reading the table.
     *
     * @param  array<string, int>  $summary
     */
    public static function headline(array $summary): string
    {
        if ($summary['accounts'] === 0) {
            return 'There are no staff accounts yet.';
        }

        $percent = (int) round($summary['using'] / $summary['accounts'] * 100);

        return "{$summary['using']} of {$summary['accounts']} staff accounts used the system ({$percent}%).";
    }

    /**
     * What one account did, as a sentence: "Signed in 3 times, added 1 member, uploaded 1 Excel file (345 member records)."
     *
     * @param  array<string, mixed>  $row
     */
    public static function describe(array $row): string
    {
        $say = fn (int $count, string $verb, string $thing): ?string => $count === 0 ? null : "{$verb} ".number_format($count).' '.Str::plural($thing, $count);

        $parts = array_filter([
            match (true) {
                $row['sign_ins'] === 0 => null,
                $row['sign_ins'] === 1 => 'signed in once',
                default => 'signed in '.number_format($row['sign_ins']).' times',
            },
            $say($row['members_added'], 'added', 'member'),
            $say($row['members_edited'], 'edited', 'member'),
            $row['files_imported'] === 0 ? null : $say($row['files_imported'], 'uploaded', 'Excel file')
                .($row['records_imported'] > 0 ? ' ('.number_format($row['records_imported']).' member '.Str::plural('record', $row['records_imported']).')' : ''),
            $say($row['sms_sent'], 'sent', 'text message'),
            $say($row['reports_exported'], 'downloaded', 'report'),
        ]);

        if ($parts === []) {
            return $row['status'] === 'using' ? 'Made other changes in the system (settings or records).' : 'Nothing recorded in this period.';
        }

        return Str::ucfirst(implode(', ', $parts)).'.';
    }

    public function download(string $period): StreamedResponse
    {
        $report = $this->build($period);

        $pdf = Pdf::loadView('reports.usage', [
            ...$report,
            'headline' => self::headline($report['summary']),
            'statuses' => self::STATUSES,
            'generatedAt' => now(),
            'logo' => app(ReportService::class)->logoDataUri(),
        ])->setPaper('a4', 'landscape');

        $fileName = 'system-usage-'.str($report['period_label'])->slug().'-'.$report['until']->format('Y-m-d').'.pdf';

        return response()->streamDownload(fn () => print ($pdf->output()), $fileName, ['Content-Type' => 'application/pdf']);
    }
}
