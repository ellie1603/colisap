<?php

namespace Tests\Feature\Api;

use App\Filament\Pages\MonitoringApiKeys;
use App\Models\Application;
use App\Models\Member;
use App\Models\MonitoringApiKey;
use App\Models\MonitoringApiLog;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

/**
 * Conformance tests required by UNIVERSAL_BMPC_MONITORING_INTEGRATION.md §28.
 */
class MonitoringApiTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    private string $rawKey;

    protected function setUp(): void
    {
        parent::setUp();

        config(['monitoring.enabled' => true, 'monitoring.rate_limit' => 60, 'monitoring.system.id' => 'bmpc-colisap']);
        [, $this->rawKey] = MonitoringApiKey::generate('BMPC ONE', array_keys(MonitoringApiKey::SCOPES));
    }

    private function callApi(string $endpoint, ?string $key = null): TestResponse
    {
        return $this->withToken($key ?? $this->rawKey)->getJson("/api/monitoring/v1/{$endpoint}");
    }

    private function assertEnvelope(TestResponse $response): void
    {
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('system.id', 'bmpc-colisap')
            ->assertJsonPath('system.api_version', 'v1')
            ->assertJsonStructure(['generated_at']);

        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $response->json('generated_at')), 'generated_at is ISO-8601.');
    }

    public function test_the_key_is_stored_only_as_a_hash_and_has_the_standard_format(): void
    {
        $key = MonitoringApiKey::sole();

        $this->assertStringStartsWith('bmmon_live_', $this->rawKey);
        $this->assertSame(hash('sha256', $this->rawKey), $key->key_hash);
        $this->assertSame(substr($this->rawKey, -4), $key->key_last_four);
        $this->assertStringNotContainsString($this->rawKey, json_encode(MonitoringApiKey::query()->toBase()->get()));
        $this->assertArrayNotHasKey('key_hash', $key->toArray());
    }

    public function test_health_and_system_follow_the_contract(): void
    {
        $health = $this->callApi('health');
        $this->assertEnvelope($health);
        $health->assertJsonPath('database', 'connected');
        $this->assertContains($health->json('status'), ['online', 'degraded']);

        $system = $this->callApi('system');
        $this->assertEnvelope($system);
        $system->assertJsonPath('capabilities', ['summary' => true, 'analytics' => true, 'reports' => true, 'activity' => true, 'exports' => false]);

        $this->assertSame($health->json('system'), $system->json('system'), 'The system identity is stable.');
    }

    public function test_summary_metrics_are_real_numbers_with_unique_keys(): void
    {
        Member::factory()->count(3)->create(['status' => 'active', 'savings_balance' => 1000]);
        Application::factory()->create();

        $response = $this->callApi('summary');
        $this->assertEnvelope($response);

        $metrics = collect($response->json('data.metrics'));
        $this->assertLessThanOrEqual(60, $metrics->count());
        $this->assertSame($metrics->count(), $metrics->pluck('key')->unique()->count());
        $metrics->each(function (array $metric) {
            $this->assertIsNumeric($metric['value']);
            $this->assertContains($metric['type'], ['number', 'currency', 'percentage', 'duration']);
        });

        $this->assertSame(Member::count(), $metrics->firstWhere('key', 'total_members')['value']);
        $this->assertSame(1, $metrics->firstWhere('key', 'pending_applications')['value']);
        $this->assertSame('currency', $metrics->firstWhere('key', 'total_savings_balance')['type']);
    }

    public function test_analytics_has_metrics_series_and_distributions_within_limits(): void
    {
        Member::factory()->create(['approval_date' => '2026-03-15']);

        $response = $this->callApi('analytics?from=2026-01-01&to=2026-06-30');
        $this->assertEnvelope($response);
        $response->assertJsonPath('filters', ['from' => '2026-01-01', 'to' => '2026-06-30']);

        $series = collect($response->json('data.series'));
        $this->assertLessThanOrEqual(30, $series->count());
        $approvals = $series->firstWhere('key', 'members_approved');
        $this->assertCount(6, $approvals['points']);
        $this->assertSame(1, $approvals['points'][2]['value'], 'March has the one approval.');

        foreach ($response->json('data.distributions') as $distribution) {
            $this->assertLessThanOrEqual(100, count($distribution['items']));
        }

        $this->assertNotEmpty($response->json('data.metrics'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidRanges(): array
    {
        return [
            'not a date' => ['from=yesterday'],
            'impossible date' => ['from=2026-02-30'],
            'from after to' => ['from=2026-06-01&to=2026-01-01'],
            'longer than five years' => ['from=2010-01-01&to=2026-01-01'],
        ];
    }

    #[DataProvider('invalidRanges')]
    public function test_an_invalid_date_range_is_rejected(string $query): void
    {
        $this->callApi("analytics?{$query}")
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'error' => ['code' => 'INVALID_DATE_RANGE', 'message' => $this->callApi("analytics?{$query}")->json('error.message')]]);
    }

    public function test_reports_and_activity_follow_the_contract_without_personal_data(): void
    {
        $member = Member::factory()->create(['account_name' => 'SECRETNAME, JUANITA', 'account_no' => '00199999999']);
        $member->update(['contact_number' => '09170000000']);

        $reports = $this->callApi('reports');
        $this->assertEnvelope($reports);
        $this->assertLessThanOrEqual(100, count($reports->json('data.reports')));
        $this->assertNotEmpty($reports->json('data.reports.0.id'));

        $activity = $this->callApi('activity');
        $this->assertEnvelope($activity);
        $entries = $activity->json('data.activity');
        $this->assertNotEmpty($entries);
        $this->assertLessThanOrEqual(100, count($entries));
        $this->assertArrayHasKey('timestamp', $entries[0]);
        $this->assertStringNotContainsString('SECRETNAME', $activity->getContent());
        $this->assertStringNotContainsString('00199999999', $activity->getContent());
        $this->assertStringNotContainsString('09170000000', $activity->getContent());
    }

    /**
     * @return array<string, array{0: string|null, 1: int, 2: string}>
     */
    public static function rejectedCredentials(): array
    {
        return [
            'missing key' => [null, 401, 'UNAUTHORIZED'],
            'invalid key' => ['bmmon_live_not-a-real-key', 401, 'UNAUTHORIZED'],
            'revoked key' => ['revoked', 401, 'UNAUTHORIZED'],
            'expired key' => ['expired', 401, 'UNAUTHORIZED'],
            'disabled key' => ['disabled', 401, 'UNAUTHORIZED'],
            'wrong scope' => ['reports-only', 403, 'FORBIDDEN'],
        ];
    }

    #[DataProvider('rejectedCredentials')]
    public function test_bad_credentials_get_the_standard_error_and_no_data(?string $credential, int $status, string $code): void
    {
        $key = match ($credential) {
            'revoked' => tap(MonitoringApiKey::generate('Old', ['monitoring:read']), fn ($made) => $made[0]->revoke())[1],
            'expired' => tap(MonitoringApiKey::generate('Old', ['monitoring:read'], now()->addDay()), fn ($made) => $made[0]->update(['expires_at' => now()->subMinute()]))[1],
            'disabled' => tap(MonitoringApiKey::generate('Paused', ['monitoring:read']), fn ($made) => $made[0]->update(['status' => 'disabled']))[1],
            'reports-only' => MonitoringApiKey::generate('Reports', ['reports:read'])[1],
            default => $credential,
        };

        $request = $key === null ? $this->getJson('/api/monitoring/v1/summary') : $this->withToken($key)->getJson('/api/monitoring/v1/summary');

        $request->assertStatus($status)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', $code)
            ->assertJsonMissingPath('data');
    }

    public function test_a_turned_off_api_answers_service_unavailable(): void
    {
        config(['monitoring.enabled' => false]);

        $this->callApi('health')->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
    }

    public function test_requests_beyond_the_rate_limit_get_429(): void
    {
        config(['monitoring.rate_limit' => 3]);
        RateLimiter::clear('monitoring-api:'.MonitoringApiKey::sole()->id.':standard');

        foreach (range(1, 3) as $attempt) {
            $this->callApi('health')->assertOk();
        }

        $this->callApi('health')->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After');
    }

    public function test_the_api_is_read_only_and_unknown_endpoints_use_the_error_shape(): void
    {
        $count = Member::count();

        $this->withToken($this->rawKey)->postJson('/api/monitoring/v1/summary', ['status' => 'deceased'])->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->withToken($this->rawKey)->deleteJson('/api/monitoring/v1/activity')->assertNotFound();
        $this->callApi('members')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertSame($count, Member::count());
    }

    public function test_every_request_is_logged_without_the_key(): void
    {
        Log::spy();

        $this->callApi('summary')->assertOk();
        $this->callApi('summary', 'bmmon_live_wrong')->assertUnauthorized();

        $this->assertSame([200, 401], MonitoringApiLog::orderBy('id')->pluck('http_status')->all());
        $this->assertSame('/v1/summary', MonitoringApiLog::first()->endpoint);
        $this->assertNotNull(MonitoringApiKey::sole()->last_used_at);
        $this->assertStringNotContainsString($this->rawKey, json_encode(MonitoringApiLog::query()->toBase()->get()));
    }

    public function test_responses_stay_well_under_one_mebibyte(): void
    {
        Member::factory()->count(50)->create();

        foreach (['health', 'system', 'summary', 'analytics', 'reports', 'activity'] as $endpoint) {
            $this->assertLessThan(1048576, strlen($this->callApi($endpoint)->getContent()), $endpoint);
        }
    }

    public function test_the_super_admin_generates_a_key_shown_once_and_can_rotate_and_revoke_it(): void
    {
        $this->actingAsRole(Permissions::CRS);
        $this->get(MonitoringApiKeys::getUrl())->assertForbidden();

        $this->actingAsRole(Permissions::ADMIN);
        $this->get(MonitoringApiKeys::getUrl())->assertForbidden();

        $this->actingAsDeveloper();
        $this->get(MonitoringApiKeys::getUrl())->assertOk();

        $page = Livewire::test(MonitoringApiKeys::class)
            ->mountAction('generate')
            ->set('mountedActionsData.0.name', 'BMPC ONE Live')
            ->set('mountedActionsData.0.scopes', ['monitoring:read', 'reports:read'])
            ->callMountedAction()
            ->assertNotified('API key generated');

        $raw = $page->get('newKey');
        $key = MonitoringApiKey::where('name', 'BMPC ONE Live')->sole();
        $this->assertSame(['monitoring:read', 'reports:read'], $key->scopes);
        $this->assertSame(MonitoringApiKey::hash($raw), $key->key_hash);
        $this->withToken($raw)->getJson('/api/monitoring/v1/health')->assertOk();

        $page->call('dismissNewKey')->assertSet('newKey', null)->assertDontSee($raw);

        $page->callTableAction('rotate', $key);
        $rotated = $page->get('newKey');
        $this->assertSame('revoked', $key->fresh()->status);
        $this->withToken($raw)->getJson('/api/monitoring/v1/health')->assertUnauthorized();
        $this->withToken($rotated)->getJson('/api/monitoring/v1/health')->assertOk();

        $page->callTableAction('revoke', MonitoringApiKey::where('key_hash', MonitoringApiKey::hash($rotated))->sole());
        $this->withToken($rotated)->getJson('/api/monitoring/v1/health')->assertUnauthorized();
    }
}
