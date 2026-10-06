<x-filament-widgets::widget>
    <section class="colisap-hero">
        <svg class="colisap-hero__ring" viewBox="0 0 400 400" fill="none" aria-hidden="true">
            <circle cx="200" cy="200" r="190" stroke="currentColor" stroke-width="1.5" />
            <circle cx="200" cy="200" r="140" stroke="currentColor" stroke-width="1" opacity=".55" />
            <circle cx="200" cy="200" r="90" stroke="currentColor" stroke-width="1" opacity=".3" />
        </svg>

        <div class="colisap-hero__main">
            <div class="colisap-hero__intro">
                <p class="colisap-hero__eyebrow">
                    <span class="colisap-hero__live" aria-hidden="true"></span>
                    Barbaza Multi-Purpose Cooperative &middot; Coop Life Savings Program
                </p>
                <h1 class="colisap-hero__title">{{ $greeting }}@if ($firstName), <span>{{ $firstName }}</span>@endif</h1>
                <p class="colisap-hero__lead">COLISAP portfolio overview @if ($ownBranchName) for the {{ $ownBranchName }} branch @endif as of {{ $today }}. Key figures refresh automatically every minute.</p>
            </div>

            <nav class="colisap-hero__actions" aria-label="Quick actions">
                @foreach ($actions as $action)
                    <a href="{{ $action['url'] }}" @class(['colisap-hero__action', 'colisap-hero__action--primary' => $action['primary']])>
                        <x-filament::icon :icon="$action['icon']" class="colisap-hero__action-icon" />
                        {{ $action['label'] }}
                    </a>
                @endforeach
            </nav>
        </div>

        <div class="colisap-hero__policy">
            <span class="colisap-hero__policy-label">Policy in force</span>
            <ul>
                @foreach ($rules as $rule)
                    <li>{{ $rule }}</li>
                @endforeach
            </ul>
        </div>
    </section>
</x-filament-widgets::widget>
