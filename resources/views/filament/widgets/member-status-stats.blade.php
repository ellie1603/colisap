<x-filament-widgets::widget>
    <div class="colisap-kpis" wire:poll.60s>
        <a href="{{ $totalUrl }}" class="colisap-kpi colisap-kpi--total">
            <div class="colisap-kpi__head">
                <span class="colisap-kpi__label">Total members</span>
                <x-filament::icon icon="heroicon-m-arrow-up-right" class="colisap-kpi__go" />
            </div>

            <p class="colisap-kpi__value" x-data x-init="colisapCountUp($el, {{ $total }})">{{ number_format($total) }}</p>
            <p class="colisap-kpi__sub">All COLISAP records across every status</p>

            <div class="colisap-kpi__stack" role="img" aria-label="Member status distribution">
                @foreach ($breakdown as $status)
                    @if ($status['count'] > 0)
                        <span class="colisap-status--{{ $status['key'] }}" style="flex-grow: {{ round($status['percent'], 2) }}" title="{{ $status['label'] }}: {{ number_format($status['count']) }}"></span>
                    @endif
                @endforeach
            </div>

            <ul class="colisap-kpi__legend">
                @foreach ($breakdown as $status)
                    <li>
                        <span class="colisap-kpi__dot colisap-status--{{ $status['key'] }}"></span>
                        <span class="colisap-kpi__legend-label">{{ $status['label'] }}</span>
                        <span class="colisap-kpi__legend-value">{{ number_format($status['count']) }}</span>
                    </li>
                @endforeach
            </ul>
        </a>

        @foreach ($cards as $card)
            <a href="{{ $card['url'] }}" class="colisap-kpi colisap-kpi--{{ $card['key'] }}">
                <div class="colisap-kpi__head">
                    <span class="colisap-kpi__icon"><x-filament::icon :icon="$card['icon']" /></span>
                    <x-filament::icon icon="heroicon-m-arrow-up-right" class="colisap-kpi__go" />
                </div>

                <span class="colisap-kpi__label">{{ $card['label'] }}</span>
                <p class="colisap-kpi__value" x-data x-init="colisapCountUp($el, {{ $card['count'] }})">{{ number_format($card['count']) }}</p>

                <div class="colisap-kpi__meter" aria-hidden="true"><span style="width: {{ $card['percent'] }}%"></span></div>
                <p class="colisap-kpi__meta"><strong>{{ $card['percent'] }}%</strong> of members</p>
                <p class="colisap-kpi__insight">{{ $card['insight'] }}</p>
            </a>
        @endforeach
    </div>
</x-filament-widgets::widget>
