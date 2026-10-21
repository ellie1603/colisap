<?php

namespace App\Http\Middleware;

use App\Models\MonitoringApiKey;
use App\Models\MonitoringApiLog;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Guards the BMPC ONE monitoring API (UNIVERSAL_BMPC_MONITORING_INTEGRATION.md §15, §19–21):
 * API switched on → Bearer key exists and matches → active, not revoked, not expired → has the scope →
 * within the rate limit. Every request is logged without the key or the Authorization header.
 */
class AuthenticateMonitoringApiKey
{
    public function handle(Request $request, Closure $next, string $scope = 'monitoring:read'): Response
    {
        $startedAt = microtime(true);
        $key = null;

        try {
            $response = $this->authorize($request, $scope, $key) ?? $this->run($request, $next);
        } catch (Throwable $exception) {
            report($exception);
            $response = self::error('INTERNAL_ERROR', 'The monitoring request could not be completed.', 500);
        }

        $this->log($request, $response, $key, $startedAt);

        return $response;
    }

    /**
     * An error response when the request may not go through, otherwise null (and $key is set).
     */
    private function authorize(Request $request, string $scope, ?MonitoringApiKey &$key): ?JsonResponse
    {
        if (! config('monitoring.enabled')) {
            return self::error('SERVICE_UNAVAILABLE', 'The monitoring API is turned off for this system.', 503);
        }

        $raw = (string) $request->bearerToken();
        $key = $raw === '' ? null : MonitoringApiKey::findByRawKey($raw);

        if ($key === null) {
            return self::error('UNAUTHORIZED', 'Invalid monitoring API credentials.', 401);
        }

        if (($reason = $key->unusableReason()) !== null) {
            return self::error('UNAUTHORIZED', "This monitoring API key is {$reason}.", 401);
        }

        if (! $key->hasScope($scope)) {
            return self::error('FORBIDDEN', "This monitoring API key does not have the {$scope} scope.", 403);
        }

        $isAnalytics = $scope === 'analytics:read';
        $limit = max(1, (int) config('monitoring.rate_limit', 60));
        $limit = $isAnalytics ? max(1, intdiv($limit, 3)) : $limit;
        $bucket = 'monitoring-api:'.$key->id.':'.($isAnalytics ? 'analytics' : 'standard');

        if (RateLimiter::tooManyAttempts($bucket, $limit)) {
            return self::error('RATE_LIMITED', 'Too many monitoring requests. Retry later.', 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($bucket));
        }

        RateLimiter::hit($bucket, 60);
        $key->markUsed();
        $request->attributes->set('monitoring_api_key', $key);

        return null;
    }

    private function run(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Never send an HTML page (login redirect, error page) to BMPC ONE.
        if (! $response instanceof JsonResponse) {
            return self::error('INTERNAL_ERROR', 'The monitoring request could not be completed.', 500);
        }

        return $response;
    }

    private function log(Request $request, Response $response, ?MonitoringApiKey $key, float $startedAt): void
    {
        try {
            MonitoringApiLog::create([
                'monitoring_api_key_id' => $key?->id,
                'endpoint' => Str::limit('/'.ltrim(Str::after($request->path(), 'api/monitoring/'), '/'), 100, ''),
                'method' => $request->method(),
                'http_status' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'source_ip' => $request->ip(),
                'requested_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The standard error shape (§21).
     */
    public static function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
    }
}
