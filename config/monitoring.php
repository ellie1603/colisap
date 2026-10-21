<?php

/*
|--------------------------------------------------------------------------
| BMPC ONE Monitoring API
|--------------------------------------------------------------------------
|
| Read-only monitoring information for the BMPC ONE centralized portal, following
| UNIVERSAL_BMPC_MONITORING_INTEGRATION.md. API keys are managed by the Administrator
| on the "Monitoring API" page and stored only as hashes — never put keys here.
|
*/

return [

    'enabled' => (bool) env('MONITORING_API_ENABLED', true),

    'system' => [
        // Permanent identity in BMPC ONE: never change it after the system is connected.
        'id' => env('MONITORING_API_SYSTEM_ID', 'bmpc-colisap'),
        'name' => env('MONITORING_API_SYSTEM_NAME', 'COLISAP Monitoring System — Barbaza MPC'),
        'api_version' => 'v1',
    ],

    // Requests per minute per API key (analytics is heavier, so it gets a third of this).
    'rate_limit' => (int) env('MONITORING_API_RATE_LIMIT', 60),

    // Request log entries older than this are removed by the daily prune.
    'log_retention_days' => 90,

];
