<x-filament-widgets::widget>
    <section class="colisap-panel colisap-actioncenter" wire:poll.60s>
        <header class="colisap-panel__header">
            <div>
                <h2 class="colisap-panel__title">Action center</h2>
                <p class="colisap-panel__desc">Items needing attention, most urgent first.</p>
            </div>
            @if ($alerts->isNotEmpty())
                <span class="colisap-actioncenter__badge">{{ $alerts->count() }}</span>
            @endif
        </header>

        @if ($alerts->isEmpty())
            <div class="colisap-actioncenter__empty">
                <x-filament::icon icon="heroicon-o-check-badge" />
                <p>All clear. Every participant is within policy.</p>
            </div>
        @else
            <ul class="colisap-actioncenter__list">
                @foreach ($alerts as $alert)
                    <li>
                        <a href="{{ $alert['url'] }}" class="colisap-actionitem colisap-actionitem--{{ $alert['severity'] }}">
                            <span class="colisap-actionitem__bar" aria-hidden="true"></span>
                            <span class="colisap-actionitem__body">
                                <span class="colisap-actionitem__severity">{{ $alert['severity'] }}</span>
                                <span class="colisap-actionitem__title">{{ $alert['title'] }}</span>
                                <span class="colisap-actionitem__desc">{{ $alert['description'] }}</span>
                            </span>
                            <span class="colisap-actionitem__count">{{ number_format($alert['count']) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-filament-widgets::widget>
