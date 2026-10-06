<?php

namespace App\Http\Controllers;

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
            'member' => $member->load('branch'),
            'logo' => app(ReportService::class)->logoDataUri(),
        ])->stream("colisap-certificate-{$member->account_no}.pdf");
    }

    public function withdrawalLetter(Member $member): StreamedResponse
    {
        Gate::authorize('view', $member);
        abort_unless($member->withdrawal_document && Storage::disk('local')->exists($member->withdrawal_document), 404);

        return Storage::disk('local')->download($member->withdrawal_document, "withdrawal-letter-{$member->account_no}.".pathinfo($member->withdrawal_document, PATHINFO_EXTENSION));
    }
}
