<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateMonitoringApiKey;
use App\Services\Monitoring\MonitoringApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * BMPC ONE Monitoring API v1 (UNIVERSAL_BMPC_MONITORING_INTEGRATION.md). GET-only and read-only.
 */
class MonitoringController extends Controller
{
    /**
     * The longest analytics range accepted, so a request cannot ask for an unbounded scan.
     */
    private const MAX_RANGE_YEARS = 5;

    public function __construct(private MonitoringApi $monitoring) {}

    public function health(): JsonResponse
    {
        return $this->respond($this->monitoring->health());
    }

    public function system(): JsonResponse
    {
        return $this->respond(['capabilities' => $this->monitoring->capabilities()]);
    }

    public function summary(): JsonResponse
    {
        return $this->respond(['data' => ['metrics' => $this->monitoring->summary()]]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        if ($from === false || $to === false) {
            return AuthenticateMonitoringApiKey::error('INVALID_DATE_RANGE', 'Use from and to dates in the format YYYY-MM-DD.', 422);
        }

        $to ??= Carbon::today();
        $from ??= $to->copy()->startOfMonth()->subMonths(11);

        if ($from->gt($to) || $from->lt($to->copy()->subYears(self::MAX_RANGE_YEARS))) {
            return AuthenticateMonitoringApiKey::error('INVALID_DATE_RANGE', 'The from date must be on or before the to date, and the range at most '.self::MAX_RANGE_YEARS.' years.', 422);
        }

        return $this->respond([
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'data' => $this->monitoring->analytics($from, $to),
        ]);
    }

    public function reports(): JsonResponse
    {
        return $this->respond(['data' => ['reports' => $this->monitoring->reports()]]);
    }

    public function activity(): JsonResponse
    {
        return $this->respond(['data' => ['activity' => $this->monitoring->activity()]]);
    }

    public function notFound(): JsonResponse
    {
        return AuthenticateMonitoringApiKey::error('NOT_FOUND', 'This monitoring endpoint does not exist.', 404);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function respond(array $payload): JsonResponse
    {
        return response()->json([
            'success' => true,
            'system' => $this->monitoring->identity(),
            'generated_at' => now()->toIso8601String(),
            ...$payload,
        ]);
    }

    /**
     * Null when not given, false when not a real YYYY-MM-DD date.
     */
    private function date(mixed $value): Carbon|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $date = Carbon::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : false;
    }
}
