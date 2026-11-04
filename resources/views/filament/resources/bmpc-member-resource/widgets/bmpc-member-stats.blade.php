<x-filament-widgets::widget>
    <div class="colisap-kpis colisap-kpis--compact">
        <div class="colisap-kpi colisap-kpi--total">
            <span class="colisap-kpi__label">Total BMPC members</span>

            <p class="colisap-kpi__value" x-data x-init="colisapCountUp($el, {{ $total }})">{{ number_format($total) }}</p>
            <p class="colisap-kpi__sub">₱{{ number_format($savings, 2) }} savings on file</p>

            <div class="colisap-kpi__stack" role="img" aria-label="BMPC members in and not yet in COLISAP">
                @foreach ($breakdown as $part)
                    @if ($part['count'] > 0)
                        <span class="colisap-status--{{ $part['key'] }}" style="flex-grow: {{ round($part['percent'], 2) }}" title="{{ $part['label'] }}: {{ number_format($part['count']) }}"></span>
                    @endif
                @endforeach
            </div>

            <ul class="colisap-kpi__legend colisap-kpi__legend--rows">
                @foreach ($breakdown as $part)
                    <li>
                        <span class="colisap-kpi__dot colisap-status--{{ $part['key'] }}"></span>
                        <span class="colisap-kpi__legend-label">{{ $part['label'] }}</span>
                        <span class="colisap-kpi__legend-value">{{ number_format($part['count']) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        @foreach ($cards as $card)
            <div class="colisap-kpi colisap-kpi--{{ $card['key'] }}">
                <div class="colisap-kpi__head">
                    <span class="colisap-kpi__icon"><x-filament::icon :icon="$card['icon']" /></span>
                </div>

                <span class="colisap-kpi__label">{{ $card['label'] }}</span>
                <p class="colisap-kpi__value" x-data x-init="colisapCountUp($el, {{ $card['count'] }})">{{ number_format($card['count']) }}</p>

                <div class="colisap-kpi__meter" aria-hidden="true"><span style="width: {{ $card['percent'] }}%"></span></div>
                <p class="colisap-kpi__meta"><strong>{{ $card['percent'] }}%</strong> of BMPC members</p>
            </div>
        @endforeach
    </div>
</x-filament-widgets::widget>
