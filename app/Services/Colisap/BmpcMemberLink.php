<?php

namespace App\Services\Colisap;

use App\Models\BmpcMember;
use App\Models\Member;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A COLISAP member's Savings Number is their savings account on the coop's BMPC member list. When a BMPC member has
 * that savings number and the same name (same surname and first given name, so an added middle name, initial or
 * punctuation does not matter), the COLISAP member takes their CIF Key, birthdate, contact number, segmentation,
 * savings balance and last transaction date from it. A detail the BMPC list leaves empty is kept as it is.
 */
class BmpcMemberLink
{
    private const CHUNK = 500;

    public const CHANGE_REASON = 'Updated from the BMPC member list';

    public function __construct(private MemberStatusEngine $engine) {}

    /**
     * Whether two account names ("SURNAME, FIRST MIDDLE") are the same person: same surname and first given name.
     */
    public static function namesMatch(?string $colisapName, ?string $bmpcName): bool
    {
        $colisap = self::nameKey($colisapName);

        return $colisap !== null && $colisap === self::nameKey($bmpcName);
    }

    /**
     * "DELA CRUZ, MA. TERESA (2)" → "DELACRUZ|MA": the surname and the first given name, letters only.
     */
    private static function nameKey(?string $accountName): ?string
    {
        $name = mb_strtoupper(preg_replace('/\(.*?\)/', '', (string) $accountName) ?? '');

        if (! str_contains($name, ',')) {
            return null;
        }

        [$surname, $given] = array_map('trim', explode(',', $name, 2));
        $surname = preg_replace('/[^A-Z]/', '', $surname) ?? '';
        $firstGiven = preg_replace('/[^A-Z]/', '', preg_split('/[\s.]+/', $given, -1, PREG_SPLIT_NO_EMPTY)[0] ?? '') ?? '';

        return $surname === '' || $firstGiven === '' ? null : "{$surname}|{$firstGiven}";
    }

    /**
     * Update every COLISAP member that has a matching BMPC member. Returns how many members changed.
     */
    public function syncAll(): int
    {
        $changed = 0;

        Member::query()->withoutGlobalScope('homeBranch')->whereNotNull('account_no')
            ->whereIn('account_no', BmpcMember::query()->withoutGlobalScope('homeBranch')->select('savings_no'))
            ->chunkById(self::CHUNK, function (Collection $members) use (&$changed): void {
                $changed += $this->syncMembers($members);
            });

        return $changed;
    }

    /**
     * Update the COLISAP members whose Savings Number is one of these (after a BMPC list import).
     *
     * @param  iterable<string|null>  $savingsNumbers
     */
    public function syncSavingsNumbers(iterable $savingsNumbers): int
    {
        $changed = 0;

        foreach (collect($savingsNumbers)->filter()->unique()->chunk(self::CHUNK) as $chunk) {
            $changed += $this->syncMembers(Member::query()->withoutGlobalScope('homeBranch')->whereIn('account_no', $chunk->values())->get());
        }

        return $changed;
    }

    /**
     * Update these members from the BMPC list.
     *
     * @param  iterable<int>  $memberIds
     */
    public function syncMemberIds(iterable $memberIds): int
    {
        $changed = 0;

        foreach (collect($memberIds)->filter()->unique()->chunk(self::CHUNK) as $chunk) {
            $changed += $this->syncMembers(Member::query()->withoutGlobalScope('homeBranch')->whereIn('id', $chunk->values())->get());
        }

        return $changed;
    }

    /**
     * The BMPC member that is this COLISAP member: same savings number and same name.
     */
    public function matchFor(Member $member): ?BmpcMember
    {
        if ($member->account_no === null) {
            return null;
        }

        return BmpcMember::query()->withoutGlobalScope('homeBranch')->where('savings_no', $member->account_no)->get()
            ->first(fn (BmpcMember $bmpcMember) => self::namesMatch($member->account_name, $bmpcMember->account_name));
    }

    /**
     * @param  Collection<int, Member>  $members
     */
    private function syncMembers(Collection $members): int
    {
        $members = $members->filter(fn (Member $member) => $member->account_no !== null);

        if ($members->isEmpty()) {
            return 0;
        }

        $bmpcBySavingsNo = BmpcMember::query()->withoutGlobalScope('homeBranch')
            ->whereIn('savings_no', $members->pluck('account_no')->unique()->values())
            ->get()
            ->groupBy('savings_no');
        $changed = 0;

        foreach ($members as $member) {
            $match = $bmpcBySavingsNo->get($member->account_no)
                ?->first(fn (BmpcMember $bmpcMember) => self::namesMatch($member->account_name, $bmpcMember->account_name));

            if ($match !== null && $this->apply($member, $match)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Copy the BMPC member's details onto the COLISAP member and re-check its status when the savings changed.
     */
    public function apply(Member $member, BmpcMember $bmpcMember): bool
    {
        $member->cif_key = $bmpcMember->cif_key;
        $member->birthdate = $bmpcMember->birthdate ?? $member->birthdate;
        $member->contact_number = $bmpcMember->contact_number ?? $member->contact_number;
        $member->segment = $bmpcMember->segment ?? $member->segment;

        if ($bmpcMember->savings_balance !== null) {
            $member->savings_balance = $bmpcMember->savings_balance;
        }

        $lastTransaction = $bmpcMember->last_transaction_date;

        if ($lastTransaction !== null && ($member->last_activity_date === null || $lastTransaction->gt($member->last_activity_date))) {
            $member->last_activity_date = $lastTransaction;
        }

        $savingsChanged = $member->isDirty(['savings_balance', 'last_activity_date']);

        if ($savingsChanged) {
            $member->savings_balance_as_of = Carbon::instance($bmpcMember->updated_at ?? Carbon::today())->toDateString();
            $member->savings_account_status = $this->engine->savingsDormancyFlag(
                $member->savings_balance === null ? null : (float) $member->savings_balance,
                $member->last_activity_date,
            );
        }

        if (! $member->isDirty()) {
            return false;
        }

        DB::transaction(function () use ($member, $savingsChanged): void {
            $member->withChangeReason(self::CHANGE_REASON, 'import')->save();

            if ($savingsChanged) {
                $member->refreshStatus();
            }
        });

        return true;
    }
}
