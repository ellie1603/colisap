<x-filament-panels::page>
    @if ($newKey)
        <div class="colisap-apikey" role="alert">
            <p class="colisap-apikey__title">New API key for “{{ $newKeyName }}” — copy it now</p>
            <p class="colisap-apikey__text">This is the only time the full key is shown. It is not stored anywhere, so if it is lost you must rotate it.</p>
            <div class="colisap-apikey__value" x-data="{ copied: false }">
                <code>{{ $newKey }}</code>
                <x-filament::button size="sm" icon="heroicon-m-clipboard-document"
                    x-on:click="navigator.clipboard.writeText(@js($newKey)); copied = true; setTimeout(() => copied = false, 2000)">
                    <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                </x-filament::button>
            </div>
            <x-filament::link tag="button" wire:click="dismissNewKey" color="gray" size="sm">I have saved it — hide the key</x-filament::link>
        </div>
    @endif

    {{ $this->table }}

    <x-filament::section>
        <x-slot name="heading">Connecting BMPC ONE</x-slot>
        <x-slot name="description">What to enter in BMPC ONE, and what it can see. It can only read totals — it cannot change anything in COLISAP.</x-slot>

        <dl class="colisap-apidoc">
            <dt>System URL</dt>
            <dd><code>{{ rtrim(config('app.url'), '/') }}</code></dd>
            <dt>Monitoring API key</dt>
            <dd>The key generated above, sent as <code>Authorization: Bearer bmmon_live_…</code></dd>
            <dt>System ID</dt>
            <dd><code>{{ config('monitoring.system.id') }}</code> (never changes)</dd>
            <dt>Endpoints</dt>
            <dd>
                <code>GET /api/monitoring/v1/health</code>, <code>/system</code>, <code>/summary</code>,
                <code>/analytics?from=YYYY-MM-DD&amp;to=YYYY-MM-DD</code>, <code>/reports</code>, <code>/activity</code>
            </dd>
            <dt>Shared information</dt>
            <dd>Member counts by status, category, segment and branch; savings totals; pending applications and imports; monthly trends; the report list; and recent activity described by kind only. No names, account numbers or other personal data.</dd>
            <dt>Limits</dt>
            <dd>{{ config('monitoring.rate_limit') }} requests per minute per key ({{ intdiv(config('monitoring.rate_limit'), 3) }} for analytics). The API is currently <strong>{{ config('monitoring.enabled') ? 'on' : 'off (MONITORING_API_ENABLED=false)' }}</strong>.</dd>
        </dl>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Recent requests</x-slot>
        <x-slot name="description">The last 15 calls to the monitoring API. Keys and responses are never logged.</x-slot>

        @if ($this->recentRequests->isEmpty())
            <p class="colisap-muted">No requests yet.</p>
        @else
            <div class="colisap-apilog">
                @foreach ($this->recentRequests as $request)
                    <div class="colisap-apilog__row">
                        <span class="colisap-apilog__time">{{ $request->requested_at->format('M j, g:i:s A') }}</span>
                        <code>{{ $request->method }} {{ $request->endpoint }}</code>
                        <x-filament::badge :color="$request->http_status < 400 ? 'success' : ($request->http_status === 429 ? 'warning' : 'danger')" size="sm">{{ $request->http_status }}</x-filament::badge>
                        <span class="colisap-muted">{{ $request->apiKey?->name ?? 'No valid key' }} · {{ $request->duration_ms }} ms</span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
