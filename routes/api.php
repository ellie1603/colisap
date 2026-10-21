<?php

use App\Http\Controllers\Api\MonitoringController;
use App\Http\Middleware\AuthenticateMonitoringApiKey;
use Illuminate\Support\Facades\Route;

/*
| BMPC ONE Monitoring API v1 — read-only, GET-only, Bearer monitoring API key (see config/monitoring.php).
*/
Route::prefix('monitoring/v1')->name('monitoring.v1.')->group(function () {
    Route::get('health', [MonitoringController::class, 'health'])->middleware(AuthenticateMonitoringApiKey::class.':monitoring:read')->name('health');
    Route::get('system', [MonitoringController::class, 'system'])->middleware(AuthenticateMonitoringApiKey::class.':monitoring:read')->name('system');
    Route::get('summary', [MonitoringController::class, 'summary'])->middleware(AuthenticateMonitoringApiKey::class.':monitoring:read')->name('summary');
    Route::get('analytics', [MonitoringController::class, 'analytics'])->middleware(AuthenticateMonitoringApiKey::class.':analytics:read')->name('analytics');
    Route::get('reports', [MonitoringController::class, 'reports'])->middleware(AuthenticateMonitoringApiKey::class.':reports:read')->name('reports');
    Route::get('activity', [MonitoringController::class, 'activity'])->middleware(AuthenticateMonitoringApiKey::class.':activity:read')->name('activity');

    // Anything else under the monitoring API (other paths, other methods) gets the standard error shape.
    Route::any('{any?}', [MonitoringController::class, 'notFound'])->where('any', '.*')->name('not-found');
});
