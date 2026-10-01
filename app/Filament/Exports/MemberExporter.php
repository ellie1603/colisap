<?php

namespace App\Filament\Exports;

use App\Models\Member;
use App\Services\Reports\SpreadsheetWriter;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the (already filtered) member query straight to the browser as XLSX or CSV.
 * Acct. Numbers are written as text so leading zeros survive in Excel.
 */
class MemberExporter
{
    public const FORMATS = SpreadsheetWriter::FORMATS;

    public function __construct(private SpreadsheetWriter $writer) {}

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Acct. Number', 'Account Name', 'Last Name', 'First Name', 'Middle Name', 'Suffix', 'Branch',
            'Segmentation', 'Category', 'Status', 'Savings Balance', 'Required Balance', 'Beneficiaries',
            'Application Date', 'Approval Date', 'Effectivity Date', 'Days Until Effective', 'Upgrade Requested',
            'Last Activity', 'Savings Flag', 'Dormant Since', 'Date of Death', 'Terminated', 'Termination Reason',
            'Withdrawn', 'Birthdate', 'Age', 'Contact Number', 'Address',
        ];
    }

    /**
     * @return list<string|float|int|null>
     */
    public function row(Member $member): array
    {
        return [
            $member->account_no,
            $member->account_name,
            $member->last_name,
            $member->first_name,
            $member->middle_name,
            $member->suffix,
            $member->branch?->name,
            Member::segmentLabel($member->segment),
            Member::categoryLabel($member->category),
            Member::STATUSES[$member->status] ?? $member->status,
            round((float) $member->savings_balance, 2),
            $member->minimumBalance(),
            $member->beneficiaryCount(),
            $member->application_date?->toDateString(),
            $member->approval_date?->toDateString(),
            $member->effectivityDate()?->toDateString(),
            $member->status === 'waiting' ? $member->daysUntilEffective() : null,
            $member->category_upgrade_requested_at?->toDateString(),
            $member->last_activity_date?->toDateString(),
            $member->savings_account_status,
            $member->dormant_since?->toDateString(),
            $member->date_deceased?->toDateString(),
            $member->terminated_at?->toDateString(),
            $member->termination_reason,
            $member->withdrawn_at?->toDateString(),
            $member->birthdate?->toDateString(),
            $member->age(),
            $member->contact_number,
            $member->address,
        ];
    }

    /**
     * @param  Builder<Member>  $query
     */
    public function download(Builder $query, string $format = 'xlsx'): StreamedResponse
    {
        $query = $query->with('branch')->withCount('activeBeneficiaries')->orderBy($query->qualifyColumn('id'));

        return $this->writer->download(
            'members-'.now()->format('Y-m-d-His'),
            $format,
            $this->headings(),
            (function () use ($query) {
                foreach ($query->lazy(500) as $member) {
                    yield $this->row($member);
                }
            })(),
            textColumns: [0],
        );
    }
}
