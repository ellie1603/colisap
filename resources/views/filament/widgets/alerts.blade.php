<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-bell-alert">
        <x-slot name="heading">Monitoring alerts</x-slot>
        <x-slot name="description">Critical items first. Select an alert to open the affected members.</x-slot>

        <div wire:poll.60s>
            @if ($alerts->isEmpty())
                <p class="colisap-muted">No alerts — every participant is within policy.</p>
            @else
                <div class="colisap-alerts" role="list">
                    @foreach ($alerts as $alert)
                        <a href="{{ $alert['url'] }}" class="colisap-alert colisap-alert--{{ $alert['severity'] }}" role="listitem">
                            <span class="colisap-alert__count">{{ number_format($alert['count']) }}</span>
                            <span>
                                <span class="colisap-alert__severity">{{ $alert['severity'] }}</span><br>
                                <span class="colisap-alert__title">{{ $alert['title'] }}</span><br>
                                <span class="colisap-muted">{{ $alert['description'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
