<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::get('/login', function () {
    return redirect('/admin/login');
})->name('login');

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/members/{member}/certificate', [DocumentController::class, 'certificate'])->name('members.certificate');
    Route::get('/members/{member}/withdrawal-letter', [DocumentController::class, 'withdrawalLetter'])->name('members.withdrawal-letter');
});
