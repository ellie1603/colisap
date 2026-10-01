<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\ClaimDocument;
use App\Models\Member;
use App\Services\Reports\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Printable COLISAP documents and protected file downloads. Every action re-checks authorization.
 */
class DocumentController extends Controller
{
    public function certificate(Member $member): Response
    {
        Gate::authorize('view', $member);
        abort_if($member->approval_date === null, 404);

        return Pdf::loadView('reports.certificate', [
            'member' => $member->load(['branch', 'activeBeneficiaries']),
            'logo' => app(ReportService::class)->logoDataUri(),
        ])->stream("colisap-certificate-{$member->account_no}.pdf");
    }

    public function claim(Claim $claim): Response
    {
        Gate::authorize('view', $claim);

        return Pdf::loadView('reports.claim', [
            'claim' => $claim->load(['member.branch', 'beneficiary', 'deductions', 'payouts', 'approver', 'settler']),
            'logo' => app(ReportService::class)->logoDataUri(),
        ])->stream("{$claim->claim_no}.pdf");
    }

    public function withdrawalLetter(Member $member): StreamedResponse
    {
        Gate::authorize('view', $member);
        abort_unless($member->withdrawal_document && Storage::disk('local')->exists($member->withdrawal_document), 404);

        return Storage::disk('local')->download($member->withdrawal_document, "withdrawal-letter-{$member->account_no}.".pathinfo($member->withdrawal_document, PATHINFO_EXTENSION));
    }

    public function claimDocument(ClaimDocument $claimDocument): StreamedResponse
    {
        $claim = $claimDocument->claim;

        abort_if($claim === null, 404);
        Gate::authorize('view', $claim);
        abort_unless(Storage::disk('local')->exists($claimDocument->file_path), 404);

        return Storage::disk('local')->download($claimDocument->file_path, $claimDocument->original_filename);
    }
}
