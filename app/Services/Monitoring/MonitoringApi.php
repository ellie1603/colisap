<?php

namespace App\Services\Monitoring;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\Member;
use App\Models\User;
use App\Services\Colisap\MonitoringService;
use App\Services\Policy\PolicySettings;
use App\Services\Reports\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * What COLISAP tells BMPC ONE: aggregate, read-only figures computed from the live data with the same
 * calculations as the dashboard. No member names, account numbers or other personal data leave the system.
 */
class MonitoringApi
{
    public function __construct(
        private MonitoringService $monitoring,
        private PolicySettings $policy,
    ) {}

    /**
     * @return array{id: string, name: string, api_version: string}
     */
    public function identity(): array
    {
        return [
            'id' => (string) config('monitoring.system.id'),
            'name' => (string) config('monitoring.system.name'),
            'api_version' => 'v1',
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        return ['summary' => true, 'analytics' => true, 'reports' => true, 'activity' => true, 'exports' => false];
    }

    /**
     * @return array{status: string, database: string}
     */
    public function health(): array
    {
        try {
            DB::select('select 1');
        } catch (Throwable) {
            return ['status' => 'offline', 'database' => 'disconnected'];
        }

        return ['status' => 'online', 'database' => 'connected'];
    }

    /**
     * @return list<array{key: string, label: string, value: int|float, type: string}>
     */
    public function summary(): array
    {
        $statuses = $this->monitoring->statusCounts();
        $participating = Member::query()->participating();
        $replenishment = $this->monitoring->replenishmentSummary();

        $metrics = [
            $this->metric('total_members', 'Total members', array_sum($statuses)),
            $this->metric('participating_members', 'Participating members', (clone $participating)->count()),
        ];

        foreach (Member::STATUSES as $status => $label) {
            $metrics[] = $this->metric("members_{$status}", "{$label} members", $statuses[$status] ?? 0);
        }

        foreach (Member::categoryOptions() as $category => $label) {
            $metrics[] = $this->metric("category_{$category}", "{$label} participating members", (clone $participating)->where('category', $category)->count());
        }

        return [
            ...$metrics,
            $this->metric('total_savings_balance', 'Savings balance of participating members', round((float) (clone $participating)->sum('savings_balance'), 2), 'currency'),
            $this->metric('below_minimum_balance', 'Members below the maintaining balance', $this->monitoring->belowMinimumBalance()->count()),
            $this->metric('open_replenishment_notices', 'Open replenishment notices', $replenishment['Open notices']),
            $this->metric('overdue_replenishment_notices', 'Overdue replenishment notices', $replenishment['Overdue']),
            $this->metric('upgrade_eligible', 'Members eligible for the higher category', $this->monitoring->upgradeQuery('eligible')->count()),
            $this->metric('qualified_for_upgrade', 'Members whose savings qualify them to upgrade', $this->monitoring->qualifiedForUpgrade()->count()),
            $this->metric('pending_applications', 'Pending applications', Application::query()->where('status', 'pending')->count()),
            $this->metric('imports_awaiting_approval', 'Excel imports awaiting approval', ImportBatch::query()->where('status', 'awaiting_approval')->count()),
            $this->metric('branches', 'Branches', Branch::query()->count()),
            $this->metric('active_staff_accounts', 'Active staff accounts', User::query()->where('is_active', true)->count()),
        ];
    }

    /**
     * @return array{metrics: list<array<string, mixed>>, series: list<array<string, mixed>>, distributions: list<array<string, mixed>>}
     */
    public function analytics(Carbon $from, Carbon $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $endOfRange = [$fromDate, $toDate.' 23:59:59'];
        $statuses = $this->monitoring->statusCounts();

        $applications = Application::query()->whereBetween('application_date', [$fromDate, $toDate]);

        $metrics = [
            $this->metric('members_approved', 'Members approved', Member::query()->whereBetween('approval_date', [$fromDate, $toDate])->count()),
            $this->metric('applications_filed', 'Applications filed', (clone $applications)->where('type', 'new')->count()),
            $this->metric('reapplications_filed', 'Re-applications filed', (clone $applications)->where('type', 'reapplication')->count()),
            $this->metric('applications_approved', 'Applications approved', Application::query()->where('status', 'approved')->whereBetween('approved_at', $endOfRange)->count()),
            $this->metric('applications_rejected', 'Applications rejected', Application::query()->where('status', 'rejected')->whereBetween('approved_at', $endOfRange)->count()),
            $this->metric('imports_completed', 'Excel imports completed', ImportBatch::query()->where('status', 'completed')->whereBetween('completed_at', $endOfRange)->count()),
            $this->metric('sms_sent', 'SMS reminders sent', AuditLog::query()->where('action', 'sms_sent')->whereBetween('created_at', $endOfRange)->count()),
        ];

        $series = [
            $this->monthlySeries('members_approved', 'Members approved per month', Member::query()->whereBetween('approval_date', [$fromDate, $toDate])->toBase()->pluck('approval_date'), $from, $to),
            $this->monthlySeries('applications_filed', 'Applications filed per month', Application::query()->whereBetween('application_date', [$fromDate, $toDate])->toBase()->pluck('application_date'), $from, $to),
        ];

        $branchCounts = Member::query()->participating()->selectRaw('branch_id, count(*) as total')->groupBy('branch_id')->pluck('total', 'branch_id');

        $distributions = [
            $this->distribution('member_status', 'Members by status', collect(Member::STATUSES)
                ->map(fn ($label, $status) => ['key' => $status, 'label' => $label, 'value' => $statuses[$status] ?? 0])),
            $this->distribution('benefit_category', 'Participating members by benefit category', collect(Member::categoryOptions())
                ->map(fn ($label, $category) => ['key' => (string) $category, 'label' => $label, 'value' => Member::query()->participating()->where('category', $category)->count()])),
            $this->distribution('segment', 'Participating members by segment', collect($this->monitoring->segmentCounts())
                ->map(fn ($value, $label) => ['key' => Str::slug($label, '_'), 'label' => $label, 'value' => $value])),
            $this->distribution('branch', 'Participating members by branch', Branch::query()->orderBy('sort_order')->get(['id', 'name', 'code'])
                ->map(fn (Branch $branch) => ['key' => (string) ($branch->code ?: $branch->id), 'label' => $branch->name, 'value' => (int) ($branchCounts[$branch->id] ?? 0)])),
        ];

        return ['metrics' => $metrics, 'series' => $series, 'distributions' => $distributions];
    }

    /**
     * @return list<array{id: string, name: string, description: string, type: string}>
     */
    public function reports(): array
    {
        return collect(ReportService::REPORTS)
            ->map(fn (array $report, string $id) => [
                'id' => Str::slug($id),
                'name' => ReportService::label($id),
                'description' => $report['group'].' report, downloadable in COLISAP as Excel, CSV or PDF.',
                'type' => $id === 'branch_summary' ? 'summary' : 'list',
            ])
            ->values()
            ->take(100)
            ->all();
    }

    /**
     * The latest system events, described by kind only — never by who or which member.
     *
     * @return list<array{id: string, timestamp: string, type: string, label: string}>
     */
    public function activity(): array
    {
        return AuditLog::query()
            ->latest('id')
            ->limit(100)
            ->get(['id', 'action', 'module', 'created_at'])
            ->map(fn (AuditLog $entry) => [
                'id' => 'activity-'.$entry->id,
                'timestamp' => $entry->created_at->toIso8601String(),
                'type' => $entry->action,
                'label' => $this->activityLabel($entry->module, $entry->action),
            ])
            ->all();
    }

    private function activityLabel(?string $module, string $action): string
    {
        return match ($action) {
            'login' => 'Staff member signed in',
            'sms_sent' => 'SMS reminder sent to a member',
            'report_exported' => 'Report downloaded',
            'submitted_for_approval' => 'Excel import sent for approval',
            'import_approved' => 'Excel import approved',
            'import_rejected' => 'Excel import rejected',
            'backup_created' => 'System backup made',
            'backup_restored' => 'System restored from a backup',
            default => trim(($module ? Str::singular($module).' ' : 'Record ').str_replace('_', ' ', $action)),
        };
    }

    /**
     * @param  iterable<string|null>  $dates
     * @return array{key: string, label: string, type: string, points: list<array{timestamp: string, value: int}>}
     */
    private function monthlySeries(string $key, string $label, iterable $dates, Carbon $from, Carbon $to): array
    {
        $perMonth = collect($dates)->filter()->countBy(fn ($date) => substr((string) $date, 0, 7));
        $points = [];

        for ($month = $from->copy()->startOfMonth(); $month->lte($to) && count($points) < 1000; $month->addMonth()) {
            $points[] = ['timestamp' => $month->copy()->startOfDay()->toIso8601String(), 'value' => (int) ($perMonth[$month->format('Y-m')] ?? 0)];
        }

        return ['key' => $key, 'label' => $label, 'type' => 'number', 'points' => $points];
    }

    /**
     * @param  Collection<array-key, array{key: string, label: string, value: int}>  $items
     * @return array{key: string, label: string, type: string, items: list<array{key: string, label: string, value: int}>}
     */
    private function distribution(string $key, string $label, $items): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'number', 'items' => $items->values()->take(100)->all()];
    }

    /**
     * @return array{key: string, label: string, value: int|float, type: string}
     */
    private function metric(string $key, string $label, int|float $value, string $type = 'number'): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'type' => $type];
    }
}
