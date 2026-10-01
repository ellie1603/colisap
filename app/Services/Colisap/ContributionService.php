<?php

namespace App\Services\Colisap;

use App\Models\Claim;
use App\Models\Contribution;
use App\Models\Member;
use App\Services\Policy\PolicySettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Generates participant contributions for an approved mortuary claim (Policy III.2, VI, VIII):
 *  - every ACTIVE participant (past the 180-day period) contributes the configured amount;
 *  - the coop shoulders the Diamond / Gold share of the 40K portion;
 *  - optional equal-share rule when 40K participants fall below the threshold;
 *  - optional extra contribution from 60K participants for a 60K claim;
 *  - member shares are deducted from savings, then members below the maintaining balance get notices.
 */
class ContributionService
{
    public function __construct(
        private PolicySettings $policy,
        private MemberStatusEngine $engine,
    ) {}

    /**
     * Contribution per participant for a claim, before the coop share.
     *
     * @return array{base: float, additional_60k: float, participants: int, equal_share: bool}
     */
    public function rates(Claim $claim): array
    {
        $participants = $this->participantsQuery($claim)->count();
        $participants40k = $this->participantsQuery($claim)->where('category', '40000')->count();
        $base = $this->policy->decimal('claim_contribution');
        $equalShare = false;

        if ($this->policy->bool('equal_share_enabled') && $participants40k < $this->policy->int('equal_share_threshold') && $participants > 0) {
            $base = round($this->policy->decimal('benefit_40k') / $participants, 2);
            $equalShare = true;
        }

        return [
            'base' => $base,
            'additional_60k' => $claim->category === '60000' ? $this->policy->decimal('additional_60k_contribution') : 0.0,
            'participants' => $participants,
            'equal_share' => $equalShare,
        ];
    }

    /**
     * @return array{participants: int, total: float, member_total: float, coop_total: float, notices: int}
     */
    public function generateForClaim(Claim $claim, ?Carbon $date = null): array
    {
        if (! in_array($claim->status, ['approved', 'settled'], true)) {
            throw new RuntimeException('Contributions can only be generated for approved claims.');
        }

        if ($claim->contributions()->exists()) {
            throw new RuntimeException('Contributions were already generated for this claim.');
        }

        $date ??= Carbon::today();
        $rates = $this->rates($claim);
        $reference = "CONTRIB-{$claim->claim_no}";
        $summary = ['participants' => 0, 'total' => 0.0, 'member_total' => 0.0, 'coop_total' => 0.0, 'notices' => 0];
        $affected = [];

        DB::transaction(function () use ($claim, $date, $rates, $reference, &$summary, &$affected) {
            $this->participantsQuery($claim)
                ->select(['id', 'segment', 'category', 'savings_balance'])
                ->chunkById(500, function ($members) use ($claim, $date, $rates, $reference, &$summary, &$affected) {
                    $now = now();
                    $contributions = [];
                    $transactions = [];
                    $deductions = [];

                    foreach ($members as $member) {
                        $coopPercent = $this->policy->coopSharePercentFor($member->segment);
                        $coopShare = round($rates['base'] * $coopPercent / 100, 2);
                        $additional = $member->category === '60000' ? $rates['additional_60k'] : 0.0;
                        $amount = round($rates['base'] + $additional, 2);
                        $memberShare = round($amount - $coopShare, 2);

                        $contributions[] = [
                            'member_id' => $member->id,
                            'claim_id' => $claim->id,
                            'amount' => $amount,
                            'member_share' => $memberShare,
                            'coop_share' => $coopShare,
                            'segment' => $member->segment,
                            'contribution_date' => $date->toDateString(),
                            'reference_no' => $reference,
                            'status' => $memberShare > 0 ? 'deducted' : 'waived',
                            'recorded_by' => Auth::id(),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        if ($memberShare > 0) {
                            $transactions[] = [
                                'member_id' => $member->id,
                                'type' => 'contribution_deduction',
                                'amount' => -$memberShare,
                                'balance_after' => round((float) $member->savings_balance - $memberShare, 2),
                                'transaction_date' => $date->toDateString(),
                                'reference_no' => $reference,
                                'remarks' => "Mortuary contribution for claim {$claim->claim_no}",
                                'recorded_by' => Auth::id(),
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                            $deductions[(string) $memberShare][] = $member->id;
                            $affected[] = $member->id;
                        }

                        $summary['participants']++;
                        $summary['total'] += $amount;
                        $summary['member_total'] += $memberShare;
                        $summary['coop_total'] += $coopShare;
                    }

                    Contribution::insert($contributions);
                    DB::table('savings_transactions')->insert($transactions);

                    foreach ($deductions as $share => $ids) {
                        Member::whereIn('id', $ids)->update(['savings_balance' => DB::raw('savings_balance - '.(float) $share)]);
                    }
                });
        });

        $summary = array_map(fn ($value) => is_float($value) ? round($value, 2) : $value, $summary);

        // Policy II.4: members pushed below the maintaining balance must replenish within the notice period.
        foreach (array_chunk($affected, 500) as $ids) {
            Member::whereIn('id', $ids)->get()
                ->reject(fn (Member $member) => $member->meetsMinimumBalance())
                ->each(function (Member $member) use (&$summary) {
                    $this->engine->evaluate($member);
                    $summary['notices']++;
                });
        }

        $claim->recordAudit('contributions_generated', null, [...$summary, 'rate' => $rates['base'], 'equal_share' => $rates['equal_share']], 'Mortuary contributions generated');

        return $summary;
    }

    /**
     * Effective participants who contribute: ACTIVE members other than the deceased.
     */
    private function participantsQuery(Claim $claim)
    {
        return Member::query()->where('status', 'active')->whereKeyNot($claim->member_id);
    }
}
