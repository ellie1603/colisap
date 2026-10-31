<?php

namespace App\Services\Colisap;

use App\Models\Member;
use App\Models\ReplenishmentNotice;
use App\Services\Policy\PolicySettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Applies COLISAP policy to one member:
 *  - 180-day waiting period → ACTIVE (III.1)
 *  - dormancy, and termination after N consecutive dormant months (VII.2)
 *  - maintaining balance → 15-day replenishment notice → 40K termination / 60K downgrade (II.4, VII.1, VII.3)
 *  - 90-day 40K → 60K upgrade (III.3, optional automatic apply)
 * Deceased, terminated and withdrawn members are never changed automatically.
 * Every change is saved with a reason, so it appears in member history and the audit log.
 */
class MemberStatusEngine
{
    public function __construct(private PolicySettings $policy) {}

    public function evaluate(Member $member, ?CarbonInterface $today = null): bool
    {
        $today = Carbon::instance($today ?? Carbon::today())->startOfDay();

        if (in_array($member->status, Member::TERMINAL_STATUSES, true)) {
            $this->cancelOpenNotices($member, $today, 'Member is '.Member::STATUSES[$member->status]);

            return false;
        }

        $changed = false;
        $changed = $this->evaluateLifecycle($member, $today) || $changed;

        if (! in_array($member->status, Member::TERMINAL_STATUSES, true)) {
            $changed = $this->evaluateReplenishment($member, $today) || $changed;
        }

        if (! in_array($member->status, Member::TERMINAL_STATUSES, true)) {
            $changed = $this->evaluateUpgrade($member, $today) || $changed;
        }

        return $changed;
    }

    /**
     * Give a member who is not saved yet the status the rules would give them, without saving, so an import
     * writes each new member once (and records one history entry) instead of saving and then correcting them.
     * Maintaining balance and upgrades still run on the next evaluate(), once the member exists.
     */
    public function applyStartingStatus(Member $member, ?CarbonInterface $today = null): void
    {
        if (in_array($member->status, Member::TERMINAL_STATUSES, true)) {
            return;
        }

        [$attributes] = $this->lifecycle($member, Carbon::instance($today ?? Carbon::today())->startOfDay());
        $member->fill($attributes);
    }

    /**
     * WAITING → ACTIVE ↔ DORMANT → TERMINATED.
     */
    private function evaluateLifecycle(Member $member, Carbon $today): bool
    {
        [$attributes, $reason] = $this->lifecycle($member, $today);

        if ($attributes['status'] === 'terminated') {
            return $this->terminate($member, $today, $reason, Arr::except($attributes, ['status', 'terminated_at', 'termination_reason']));
        }

        return $this->save($member, $attributes, $reason);
    }

    /**
     * The lifecycle status the rules give a member today, with the reason.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function lifecycle(Member $member, Carbon $today): array
    {
        $effectivity = $member->effectivityDate();

        if (! $effectivity || $today->lt($effectivity)) {
            return [['status' => 'waiting', 'dormant_since' => null], 'Within the '.$this->policy->int('effectivity_days').'-day waiting period (Policy III.1)'];
        }

        $dormantSince = $this->dormantSince($member, $today);

        if ($dormantSince === null) {
            $reason = $member->status === 'waiting'
                ? 'Effective '.$effectivity->toFormattedDateString().' after the '.$this->policy->int('effectivity_days').'-day waiting period (Policy III.1)'
                : 'Account activity resumed';

            return [[
                'status' => 'active',
                'dormant_since' => null,
                'activated_at' => $member->activated_at ?? $effectivity,
            ], $reason];
        }

        $terminationDate = $dormantSince->copy()->addMonthsNoOverflow($this->policy->int('dormancy_termination_months'));

        if ($this->policy->bool('auto_enforce_terminations') && $today->gte($terminationDate)) {
            $reason = 'Savings deposit dormant for '.$this->policy->int('dormancy_termination_months').' consecutive months (Policy VII.2)';

            return [[
                'dormant_since' => $dormantSince,
                'activated_at' => $member->activated_at ?? $effectivity,
                'status' => 'terminated',
                'terminated_at' => $today,
                'termination_reason' => $reason,
            ], $reason];
        }

        return [[
            'status' => 'dormant',
            'dormant_since' => $dormantSince,
            'activated_at' => $member->activated_at ?? $effectivity,
        ], 'Savings account dormant since '.$dormantSince->toFormattedDateString()];
    }

    /**
     * The savings account's dormancy from its own figures: dormant-txn when there has been no transaction for the
     * inactivity period (3 months), dormant-bal when the balance is below the dormant-in-balance amount (₱2,000),
     * null when neither. Either kind, kept for the consecutive dormant months, ends participation.
     */
    public function savingsDormancyFlag(?float $balance, ?CarbonInterface $lastTransaction, ?CarbonInterface $today = null): ?string
    {
        $today = Carbon::instance($today ?? Carbon::today())->startOfDay();

        if ($lastTransaction !== null && Carbon::instance($lastTransaction)->startOfDay()->lte($today->copy()->subMonthsNoOverflow($this->policy->int('dormancy_months')))) {
            return 'dormant-txn';
        }

        if ($balance !== null && $balance < $this->policy->decimal('dormant_balance_threshold')) {
            return 'dormant-bal';
        }

        return null;
    }

    /**
     * When the savings account became dormant, or null when it is not dormant.
     */
    public function dormantSince(Member $member, CarbonInterface $today): ?Carbon
    {
        $source = $this->policy->get('dormancy_activity_source');
        $candidates = [];

        if (in_array($source, ['masterlist_flag', 'both'], true) && str_starts_with((string) $member->savings_account_status, 'dormant')) {
            $candidates[] = $member->dormant_since ?? $member->savings_balance_as_of ?? Carbon::instance($today);
        }

        if (in_array($source, ['recorded_activity', 'both'], true) && $member->last_activity_date) {
            $becameDormant = $member->last_activity_date->copy()->addMonthsNoOverflow($this->policy->int('dormancy_months'));

            if ($becameDormant->lte($today)) {
                $candidates[] = $becameDormant;
            }
        }

        if ($candidates === []) {
            return null;
        }

        return Carbon::instance(collect($candidates)->map(fn ($date) => Carbon::instance($date))->min())->startOfDay();
    }

    /**
     * Maintaining balance: issue a notice, resolve it when replenished, enforce it when it expires.
     * A member whose balance is not known yet (a list without savings) is left alone: unknown is not ₱0.
     */
    private function evaluateReplenishment(Member $member, Carbon $today): bool
    {
        if ($member->savings_balance === null) {
            return false;
        }

        $required = $member->minimumBalance();
        $balance = (float) $member->savings_balance;
        $notice = $member->openReplenishmentNotice()->first();

        if ($notice && $notice->category !== $member->category) {
            $notice->update(['status' => 'cancelled', 'resolved_at' => $today, 'remarks' => 'Category changed']);
            $notice = null;
        }

        if ($balance >= $required) {
            $notice?->update(['status' => 'replenished', 'resolved_at' => $today]);

            return false;
        }

        if (! $notice) {
            if (! $this->policy->bool('auto_issue_replenishment_notices')) {
                return false;
            }

            $this->issueNotice($member, $today);

            return true;
        }

        if (! $this->policy->bool('auto_enforce_terminations') || $today->lte($notice->deadline)) {
            return false;
        }

        if ($member->category === '60000') {
            $notice->update(['status' => 'downgraded', 'resolved_at' => $today]);

            $this->save($member, ['category' => '40000', 'category_upgrade_requested_at' => null],
                'Savings not replenished to ₱'.number_format($required, 2).' within '.$this->policy->int('replenishment_days').' days of notice (Policy VII.3)');

            $member->unsetRelation('openReplenishmentNotice');
            $this->evaluateReplenishment($member, $today);

            return true;
        }

        $notice->update(['status' => 'terminated', 'resolved_at' => $today]);

        return $this->terminate($member, $today,
            'Savings below ₱'.number_format($required, 2).' not replenished within '.$this->policy->int('replenishment_days').' days of notice (Policy VII.1)');
    }

    public function issueNotice(Member $member, CarbonInterface $noticeDate): ReplenishmentNotice
    {
        $required = $member->minimumBalance();

        return $member->replenishmentNotices()->create([
            'category' => $member->category,
            'required_balance' => $required,
            'balance_at_notice' => (float) $member->savings_balance,
            'shortfall' => max(0, round($required - (float) $member->savings_balance, 2)),
            'notice_date' => $noticeDate,
            'deadline' => Carbon::instance($noticeDate)->addDays($this->policy->int('replenishment_days')),
            'status' => 'open',
            'issued_by' => auth()->id(),
        ]);
    }

    private function evaluateUpgrade(Member $member, Carbon $today): bool
    {
        if (! $this->policy->bool('auto_apply_upgrades') || $member->status !== 'active') {
            return false;
        }

        return $member->applyCategoryUpgradeIfEligible();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function terminate(Member $member, Carbon $today, string $reason, array $extra = []): bool
    {
        $this->save($member, [
            ...$extra,
            'status' => 'terminated',
            'terminated_at' => $today,
            'termination_reason' => $reason,
        ], $reason);

        $this->cancelOpenNotices($member, $today, 'Participation terminated');

        return true;
    }

    private function cancelOpenNotices(Member $member, Carbon $today, string $remarks): void
    {
        $member->replenishmentNotices()->where('status', 'open')->get()
            ->each(fn (ReplenishmentNotice $notice) => $notice->update(['status' => 'cancelled', 'resolved_at' => $today, 'remarks' => $remarks]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function save(Member $member, array $attributes, string $reason): bool
    {
        $member->fill($attributes);

        if (! $member->isDirty()) {
            return false;
        }

        $member->withChangeReason($member->isDirty(['status', 'category']) ? $reason : null, 'system')->save();

        return true;
    }
}
