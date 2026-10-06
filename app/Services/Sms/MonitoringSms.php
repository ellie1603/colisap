<?php

namespace App\Services\Sms;

use App\Models\Member;
use App\Models\ReplenishmentNotice;
use App\Services\Policy\PolicySettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * SMS reminders sent from the Monitoring page. Each monitoring view has its own wording, filled in
 * with the member's name, dates and amounts. Texts stay plain ASCII ("P" for peso) so a reminder
 * fits in as few SMS segments as possible.
 */
class MonitoringSms
{
    private const SIGNATURE = ' - Barbaza MPC';

    public function __construct(
        private SmsGateway $gateway,
        private PolicySettings $policy,
    ) {}

    /**
     * The reminder for one monitoring row, or null when that view has nothing to tell this member.
     */
    public function message(string $view, Member|ReplenishmentNotice $record): ?string
    {
        $member = $this->memberOf($record);

        if ($member === null) {
            return null;
        }

        $body = match ($view) {
            'effectivity' => $this->effectivityText($member),
            'upgrades' => $this->upgradeText($member),
            'replenishment' => $record instanceof ReplenishmentNotice ? $this->replenishmentText($record, $member) : null,
            'dormancy' => $this->dormancyText($member),
            default => null,
        };

        return $body === null ? null : 'Good day, '.$this->greetingName($member).'! '.$body.self::SIGNATURE;
    }

    /**
     * Send the view's reminder to every record (or one custom text to all of them). A member is texted once
     * per call, members without a usable mobile number are skipped, and each SMS is kept in the member's audit log.
     *
     * @param  iterable<Member|ReplenishmentNotice>  $records
     * @return array{sent: int, no_number: int, not_applicable: int, failed: int, errors: list<string>}
     */
    public function send(string $view, iterable $records, ?string $message = null): array
    {
        $summary = ['sent' => 0, 'no_number' => 0, 'not_applicable' => 0, 'failed' => 0, 'errors' => []];
        $outbox = [];
        $members = [];

        foreach ($records as $record) {
            $member = $this->memberOf($record);
            $text = $message ?? $this->message($view, $record);

            if ($member === null || $text === null || isset($outbox[$member->id])) {
                $summary['not_applicable']++;

                continue;
            }

            $to = SmsGateway::normalizeNumber($member->contact_number);

            if ($to === null) {
                $summary['no_number']++;

                continue;
            }

            $outbox[$member->id] = ['to' => $to, 'message' => $text];
            $members[$member->id] = $member;
        }

        foreach ($this->gateway->sendMany($outbox) as $memberId => $result) {
            if (! $result['ok']) {
                $summary['failed']++;
                $summary['errors'][] = "{$members[$memberId]->account_name}: {$result['error']}";

                continue;
            }

            $summary['sent']++;
            $members[$memberId]->recordAudit('sms_sent', null, [
                'to' => $outbox[$memberId]['to'],
                'view' => $view,
                'message' => $outbox[$memberId]['message'],
                'message_id' => $result['message_id'],
            ], 'SMS reminder sent from Monitoring');
        }

        return $summary;
    }

    private function memberOf(Member|ReplenishmentNotice $record): ?Member
    {
        return $record instanceof Member ? $record : $record->member;
    }

    private function effectivityText(Member $member): ?string
    {
        $effectivity = $member->effectivityDate();

        if ($member->status !== 'waiting' || $effectivity === null) {
            return null;
        }

        return 'Your COLISAP membership takes effect on '.$this->date($effectivity)
            .'. Please keep at least P'.$this->money($member->minimumBalance()).' in your savings to stay covered.';
    }

    private function upgradeText(Member $member): ?string
    {
        $required = 'P'.$this->money($this->policy->minimumBalanceFor('60000'));

        return match ($member->upgradeStatus()) {
            'eligible' => 'You are now eligible to upgrade your COLISAP coverage to the 60K category. Please visit '
                .$this->branchOf($member)." to complete it. Required savings: {$required}.",
            'pending' => 'Your request to upgrade your COLISAP coverage to the 60K category will be eligible on '
                .$this->date($member->upgradeEligibleDate()).". Please keep at least {$required} in your savings by then.",
            default => null,
        };
    }

    private function replenishmentText(ReplenishmentNotice $notice, Member $member): ?string
    {
        $shortfall = round((float) $notice->required_balance - (float) $member->savings_balance, 2);

        if ($notice->status !== 'open' || $shortfall <= 0) {
            return null;
        }

        $deadline = $notice->isOverdue()
            ? 'immediately (it was due on '.$this->date($notice->deadline).')'
            : 'on or before '.$this->date($notice->deadline);

        return 'Your savings of P'.$this->money((float) $member->savings_balance).' is below the P'.$this->money((float) $notice->required_balance)
            .' COLISAP maintaining balance. Please deposit at least P'.$this->money($shortfall)." {$deadline} to keep your coverage.";
    }

    private function dormancyText(Member $member): string
    {
        $termination = $member->dormant_since?->copy()->addMonthsNoOverflow($this->policy->int('dormancy_termination_months'));
        $deadline = $termination?->isFuture() ? 'on or before '.$this->date($termination) : 'as soon as possible';

        return 'Your savings account is dormant. Please make a deposit at '.$this->branchOf($member)." {$deadline} to keep your COLISAP coverage.";
    }

    private function greetingName(Member $member): string
    {
        return Str::title(Str::lower(trim((string) ($member->first_name ?: $member->account_name)))) ?: 'member';
    }

    private function branchOf(Member $member): string
    {
        return 'your Barbaza MPC branch'.($member->branch ? " ({$member->branch->name})" : '');
    }

    private function date(Carbon $date): string
    {
        return $date->format('M j, Y');
    }

    private function money(float $amount): string
    {
        return number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
    }
}
