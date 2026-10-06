<div class="colisap-login">
    <aside class="colisap-login__brand" aria-hidden="true">
        <svg class="colisap-login__ring" viewBox="0 0 600 600" fill="none">
            <circle cx="300" cy="300" r="280" stroke="currentColor" stroke-width="2" />
            <circle cx="300" cy="300" r="220" stroke="currentColor" stroke-width="1" opacity=".5" />
        </svg>

        <svg class="colisap-login__swoosh" viewBox="0 0 800 220" preserveAspectRatio="none" fill="none">
            <path d="M0 150 C 200 60, 420 220, 800 70 L800 110 C 430 250, 210 100, 0 190 Z" fill="#2E3A8C" opacity=".85" />
            <path d="M0 175 C 210 95, 430 245, 800 110 L800 140 C 440 270, 220 130, 0 210 Z" fill="#FFD200" />
            <path d="M0 200 C 220 125, 440 270, 800 140 L800 220 L0 220 Z" fill="#E8691C" />
        </svg>

        <div class="colisap-login__brand-inner">
            <div class="colisap-login__logo-tile">
                <img src="{{ asset('images/logo-192.png') }}" alt="">
            </div>

            <div class="colisap-login__copy">
                <p class="colisap-login__eyebrow">Barbaza Multi-Purpose Cooperative</p>
                <h2 class="colisap-login__headline">
                    COLISAP member monitoring, <span>made clear.</span>
                </h2>
                <p class="colisap-login__lead">
                    Import member records, track account status, and generate reports from one dashboard.
                </p>
            </div>

            <ul class="colisap-login__features">
                <li><span class="colisap-login__dot colisap-login__dot--blue"></span>Excel member import</li>
                <li><span class="colisap-login__dot colisap-login__dot--yellow"></span>Account status monitoring</li>
                <li><span class="colisap-login__dot colisap-login__dot--orange"></span>Ready-to-print reports</li>
            </ul>
        </div>
    </aside>

    <section class="colisap-login__panel">
        <div class="colisap-login__form-wrap">
            <img class="colisap-login__mobile-logo" src="{{ asset('images/logo-192.png') }}" alt="COLISAP logo">

            <header class="colisap-login__header">
                <span class="colisap-login__accent" aria-hidden="true"></span>
                <h1 class="colisap-login__title">{{ $this->getHeading() }}</h1>
                <p class="colisap-login__subtitle">{{ $this->getSubheading() }}</p>
            </header>

            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, scopes: $this->getRenderHookScopes()) }}

            <div
                wire:key="login-{{ $lockoutSecondsRemaining ? 'locked-'.now()->timestamp : 'open' }}"
                x-data="{ seconds: {{ (int) $lockoutSecondsRemaining }}, timer: null }"
                x-init="if (seconds > 0) { timer = setInterval(() => { seconds--; if (seconds <= 0) { clearInterval(timer); } }, 1000) }"
            >
                <div class="colisap-login__lockout" x-show="seconds > 0" x-cloak role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <rect x="5" y="11" width="14" height="10" rx="2" />
                        <path d="M8 11V8a4 4 0 0 1 8 0v3" />
                    </svg>
                    <div>
                        <p class="colisap-login__lockout-title">Sign-in temporarily locked</p>
                        <p class="colisap-login__lockout-text">
                            Too many failed attempts. Try again in
                            <strong x-text="Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0')"></strong>.
                        </p>
                    </div>
                </div>

                <x-filament-panels::form id="form" wire:submit="authenticate" x-bind:inert="seconds > 0" x-bind:class="{ 'colisap-login__form--locked': seconds > 0 }">
                    {{ $this->form }}

                    <x-filament-panels::form.actions
                        :actions="$this->getCachedFormActions()"
                        :full-width="$this->hasFullWidthFormActions()"
                    />
                </x-filament-panels::form>
            </div>

            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, scopes: $this->getRenderHookScopes()) }}

            <p class="colisap-login__footnote">
                &copy; {{ now()->year }} Barbaza MPC &middot; Authorized personnel only
            </p>
        </div>
    </section>
</div>
