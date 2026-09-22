<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\Contribution;
use App\Models\EligibilityRule;
use App\Models\Member;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function activeMembers()
    {
        $members = Member::where('status', 'active')->orderBy('last_name')->get();

        $pdf = Pdf::loadView('reports.active-members', [
            'members' => $members,
            'generatedAt' => now(),
        ]);

        return $pdf->download('active-members-'.now()->format('Ymd').'.pdf');
    }

    public function contributionSummary(Request $request)
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $until = $request->date('until') ?? now();

        $summary = Contribution::query()
            ->whereBetween('contribution_date', [$from->toDateString(), $until->toDateString()])
            ->selectRaw('member_id, sum(amount) as total, count(*) as count')
            ->groupBy('member_id')
            ->with('member')
            ->get();

        $pdf = Pdf::loadView('reports.contribution-summary', [
            'summary' => $summary,
            'from' => $from,
            'until' => $until,
            'grandTotal' => $summary->sum('total'),
            'generatedAt' => now(),
        ]);

        return $pdf->download('contribution-summary-'.now()->format('Ymd').'.pdf');
    }

    public function claimsProcessed(Request $request)
    {
        $from = $request->date('from') ?? now()->startOfYear();
        $until = $request->date('until') ?? now();

        $claims = Claim::query()
            ->whereBetween('date_filed', [$from->toDateString(), $until->toDateString()])
            ->with(['member', 'beneficiary'])
            ->orderBy('date_filed')
            ->get();

        $pdf = Pdf::loadView('reports.claims-processed', [
            'claims' => $claims,
            'from' => $from,
            'until' => $until,
            'generatedAt' => now(),
        ]);

        return $pdf->download('claims-processed-'.now()->format('Ymd').'.pdf');
    }

    public function eligibilityStatus()
    {
        $rule = EligibilityRule::where('is_active', true)->first();
        $members = Member::where('status', 'active')->orderBy('last_name')->get();

        $members->each(function (Member $member) {
            $member->setAttribute('eligible', $member->isEligible());
            $member->setAttribute('balance', $member->contributionBalance());
            $member->setAttribute('months', $member->membershipMonths());
        });

        $pdf = Pdf::loadView('reports.eligibility-status', [
            'members' => $members,
            'rule' => $rule,
            'generatedAt' => now(),
        ]);

        return $pdf->download('eligibility-status-'.now()->format('Ymd').'.pdf');
    }
}
