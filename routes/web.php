<?php

use App\Http\Controllers\ReportController;
use App\Models\ClaimDocument;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return redirect('/admin');
});

Route::get('/login', function () {
    return redirect('/admin/login');
})->name('login');

Route::get('/claim-documents/{claimDocument}/download', function (ClaimDocument $claimDocument) {
    abort_unless(Storage::disk('local')->exists($claimDocument->file_path), 404);

    return Storage::disk('local')->download($claimDocument->file_path, $claimDocument->original_filename);
})->middleware(['auth'])->name('claim-documents.download');

Route::middleware(['auth'])->prefix('reports')->name('reports.')->group(function () {
    Route::get('active-members', [ReportController::class, 'activeMembers'])->name('active-members');
    Route::get('contribution-summary', [ReportController::class, 'contributionSummary'])->name('contribution-summary');
    Route::get('claims-processed', [ReportController::class, 'claimsProcessed'])->name('claims-processed');
    Route::get('eligibility-status', [ReportController::class, 'eligibilityStatus'])->name('eligibility-status');
});
