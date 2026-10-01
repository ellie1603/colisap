<?php

namespace App\Services\Colisap;

use App\Filament\Pages\Monitoring;
use App\Filament\Resources\ClaimResource;
use App\Filament\Resources\MemberResource;
use App\Models\Branch;
use App\Models\Claim;
use App\Models\Member;
use App\Models\ReplenishmentNotice;
use App\Services\Policy\PolicySettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Live monitoring figures for the dashboard, monitoring page, alerts and reports.
 * Every number is an aggregate query on the member database — nothing is stored or hard-coded.
 */
class MonitoringService
{
    public function __construct(private PolicySettings $policy) {}

    /**
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = Member::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(Member::STATUSES)->map(fn ($label, $status) => (int) ($counts[$status] ?? 0))->all();
    }

    /**
     * @return array<string, int>
     */
    public function categoryCounts(): array
    {
        $counts = Member::query()->participating()->selectRaw('category, count(*) as total')->groupBy('category')->pluck('total', 'category');

        return collect(Member::CATEGORIES)->mapWithKeys(fn ($label, $category) => [$label => (int) ($counts[$category] ?? 0)])->all();
    }

    /**
     * @return array<string, int>
     */
    public function segmentCounts(): array
    {
        $counts = Member::query()->participating()->selectRaw('segment, count(*) as total')->groupBy('segment')->pluck('total', 'segment');
        $result = collect(Member::SEGMENTS)->mapWithKeys(fn ($label, $segment) => [$label => (int) ($counts[$segment] ?? 0)])->all();
        $result['Unassigned'] = (int) $counts->filter(fn ($total, $segment) => ! isset(Member::SEGMENTS[$segment]))->sum();

        return $result;
    }

    /**
     * @return array<string, array<string, int>> branch => status => count
     */
    public function statusByBranch(): array
    {
        $rows = Member::query()
            ->selectRaw('branch_id, status, count(*) as total')
            ->groupBy('branch_id', 'status')
            ->get();

        $result = [];
        $branchNames = Branch::query()->orderBy('sort_order')->pluck('name', 'id');

        foreach ($branchNames as $name) {
            $result[$name] = collect(Member::STATUSES)->map(fn () => 0)->all();
        }

        foreach ($rows as $row) {
            $name = $branchNames[$row->branch_id] ?? 'No branch';
            $result[$name] ??= collect(Member::STATUSES)->map(fn () => 0)->all();
            $result[$name][$row->status] = (int) $row->total;
        }

        return $result;
    }

    /**
     * New approvals per month for the last 12 months.
     *
     * @return array<string, int>
     */
    public function monthlyTrend(int $months = 12): array
    {
        $start = Carbon::today()->startOfMonth()->subMonths($months - 1);
        $dates = Member::query()->whereNotNull('approval_date')->where('approval_date', '>=', $start)->pluck('approval_date');
        $result = [];

        for ($i = 0; $i < $months; $i++) {
            $result[$start->copy()->addMonths($i)->format('M Y')] = 0;
        }

        foreach ($dates as $date) {
            $key = Carbon::parse($date)->format('M Y');

            if (array_key_exists($key, $result)) {
                $result[$key]++;
            }
        }

        return $result;
    }

    /**
     * Participating members grouped by number of active beneficiaries.
     *
     * @return array<string, int>
     */
    public function beneficiaryCompliance(): array
    {
        $counts = DB::table('members')
            ->leftJoinSub(
                DB::table('beneficiaries')->select('member_id', DB::raw('count(*) as c'))->where('is_active', true)->whereNull('deleted_at')->groupBy('member_id'),
                'b', 'b.member_id', '=', 'members.id',
            )
            ->whereNull('members.deleted_at')
            ->whereNotIn('members.status', Member::TERMINAL_STATUSES)
            ->selectRaw('COALESCE(b.c, 0) as beneficiaries, count(*) as total')
            ->groupBy(DB::raw('COALESCE(b.c, 0)'))
            ->pluck('total', 'beneficiaries');

        $max = $this->policy->int('max_beneficiaries');
        $result = ['None' => (int) ($counts[0] ?? 0)];

        for ($i = 1; $i <= $max; $i++) {
            $result[$i.' beneficiar'.($i === 1 ? 'y' : 'ies')] = (int) ($counts[$i] ?? 0);
        }

        $over = (int) $counts->filter(fn ($total, $beneficiaries) => (int) $beneficiaries > $max)->sum();

        if ($over > 0) {
            $result['Over '.$max] = $over;
        }

        return $result;
    }

    /**
     * @param  Builder<Member>  $query
     * @return Builder<Member>
     */
    public function withoutBeneficiaries(?Builder $query = null): Builder
    {
        return ($query ?? Member::query())->participating()->whereDoesntHave('activeBeneficiaries');
    }

    /**
     * Waiting members whose effectivity date falls within the next $days days.
     *
     * @return Builder<Member>
     */
    public function becomingEffectiveWithin(int $days): Builder
    {
        $effectivityDays = $this->policy->int('effectivity_days');
        $today = Carbon::today();

        return Member::query()
            ->where('status', 'waiting')
            ->whereNotNull('approval_date')
            ->where('approval_date', '>', $today->copy()->subDays($effectivityDays)->toDateString())
            ->where('approval_date', '<=', $today->copy()->subDays($effectivityDays)->addDays($days)->toDateString());
    }

    /**
     * @return array<string, int>
     */
    public function effectivityBuckets(): array
    {
        return [
            'Within 30 days' => $this->becomingEffectiveWithin(30)->count(),
            '31–60 days' => $this->becomingEffectiveWithin(60)->count() - $this->becomingEffectiveWithin(30)->count(),
            '61–90 days' => $this->becomingEffectiveWithin(90)->count() - $this->becomingEffectiveWithin(60)->count(),
            'Over 90 days' => Member::where('status', 'waiting')->count() - $this->becomingEffectiveWithin(90)->count(),
        ];
    }

    /**
     * 40K members with an upgrade request: 'eligible' (waiting period over) or 'pending'.
     *
     * @return Builder<Member>
     */
    public function upgradeQuery(string $state, ?int $withinDays = null): Builder
    {
        $cutoff = Carbon::today()->subDays($this->policy->int('upgrade_wait_days'));
        $query = Member::query()->participating()->where('category', '40000')->whereNotNull('category_upgrade_requested_at');

        return match ($state) {
            'eligible' => $query->where('category_upgrade_requested_at', '<=', $cutoff->toDateString()),
            default => $query->where('category_upgrade_requested_at', '>', $cutoff->toDateString())
                ->when($withinDays, fn (Builder $query) => $query->where('category_upgrade_requested_at', '<=', $cutoff->copy()->addDays($withinDays)->toDateString())),
        };
    }

    /**
     * @return array<string, int>
     */
    public function upgradeSummary(): array
    {
        $window = $this->policy->int('alert_window_days');

        return [
            'Eligible now' => $this->upgradeQuery('eligible')->count(),
            "Eligible within {$window} days" => $this->upgradeQuery('pending', $window)->count(),
            'Still waiting' => $this->upgradeQuery('pending')->count() - $this->upgradeQuery('pending', $window)->count(),
        ];
    }

    /**
     * @return Builder<Member>
     */
    public function belowMinimumBalance(): Builder
    {
        return Member::query()->participating()->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('category', '60000')->where('savings_balance', '<', $this->policy->decimal('min_balance_60k')))
            ->orWhere(fn (Builder $query) => $query->where('category', '<>', '60000')->where('savings_balance', '<', $this->policy->decimal('min_balance_40k'))));
    }

    /**
     * @return array<string, int>
     */
    public function replenishmentSummary(): array
    {
        $open = ReplenishmentNotice::query()->where('status', 'open');
        $today = Carbon::today();
        $warn = $today->copy()->addDays($this->policy->int('replenishment_warning_days'));

        return [
            'Open notices' => (clone $open)->count(),
            'Deadline within '.$this->policy->int('replenishment_warning_days').' days' => (clone $open)->whereBetween('deadline', [$today->toDateString(), $warn->toDateString()])->count(),
            'Overdue' => (clone $open)->where('deadline', '<', $today->toDateString())->count(),
            'Below minimum, no notice' => $this->belowMinimumBalance()->whereDoesntHave('openReplenishmentNotice')->count(),
        ];
    }

    /**
     * Dormant members whose termination date is within $days days.
     *
     * @return Builder<Member>
     */
    public function dormancyApproachingTermination(int $days): Builder
    {
        $months = $this->policy->int('dormancy_termination_months');

        return Member::query()
            ->where('status', 'dormant')
            ->whereNotNull('dormant_since')
            ->where('dormant_since', '<=', Carbon::today()->addDays($days)->subMonthsNoOverflow($months)->toDateString());
    }

    /**
     * @return array<string, int>
     */
    public function claimSummary(): array
    {
        $counts = Claim::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(Claim::STATUSES)->mapWithKeys(fn ($label, $status) => [$label => (int) ($counts[$status] ?? 0)])->all();
    }

    /**
     * Monitoring alerts with severity INFO / WARNING / CRITICAL.
     *
     * @return Collection<int, array{severity: string, title: string, description: string, count: int, url: ?string}>
     */
    public function alerts(): Collection
    {
        $window = $this->policy->int('alert_window_days');
        $replenishment = $this->replenishmentSummary();
        $warnDays = $this->policy->int('replenishment_warning_days');

        $alerts = [
            ['critical', 'Replenishment deadline passed', 'Open notices past their 15-day deadline (will be enforced on the next status run).', $replenishment['Overdue'], Monitoring::getUrl(['tab' => 'replenishment'])],
            ['critical', 'Dormant — termination approaching', "Dormant members reaching termination within {$this->policy->int('dormancy_warning_days')} days.", $this->dormancyApproachingTermination($this->policy->int('dormancy_warning_days'))->count(), Monitoring::getUrl(['tab' => 'dormancy'])],
            ['warning', 'Replenishment deadline approaching', "Open notices due within {$warnDays} days.", $replenishment["Deadline within {$warnDays} days"], Monitoring::getUrl(['tab' => 'replenishment'])],
            ['warning', 'Savings below minimum', 'Participating members below their maintaining balance.', $this->belowMinimumBalance()->count(), Monitoring::getUrl(['tab' => 'replenishment'])],
            ['warning', 'Beneficiary information incomplete', 'Participating members with no beneficiary.', $this->withoutBeneficiaries()->count(), Monitoring::getUrl(['tab' => 'beneficiaries'])],
            ['warning', 'Claims requiring action', 'Mortuary claims submitted or under review.', Claim::whereIn('status', ['submitted', 'under_review'])->count(), ClaimResource::getUrl('index')],
            ['info', 'Approved claims awaiting settlement', 'Approved claims not yet settled.', Claim::where('status', 'approved')->count(), ClaimResource::getUrl('index')],
            ['info', 'Waiting period ending soon', "Members becoming effective within {$window} days.", $this->becomingEffectiveWithin($window)->count(), Monitoring::getUrl(['tab' => 'effectivity'])],
            ['info', 'Upgrade eligibility', 'Members whose 90-day upgrade waiting period is complete.', $this->upgradeQuery('eligible')->count(), Monitoring::getUrl(['tab' => 'upgrades'])],
            ['info', 'Incomplete member information', 'Members without approval date or branch.', Member::query()->participating()->where(fn (Builder $q) => $q->whereNull('approval_date')->orWhereNull('branch_id'))->count(), MemberResource::getUrl('index')],
        ];

        return collect($alerts)
            ->map(fn (array $alert) => array_combine(['severity', 'title', 'description', 'count', 'url'], $alert))
            ->filter(fn (array $alert) => $alert['count'] > 0)
            ->values();
    }
}
