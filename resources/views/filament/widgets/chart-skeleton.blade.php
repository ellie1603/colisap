@php
    $columnSpan = is_array($columnSpan) ? $columnSpan : ['default' => $columnSpan];
    $columnStart = is_array($columnStart) ? $columnStart : ['default' => $columnStart];
    $barHeights = ($columnSpan['default'] ?? null) === 'full'
        ? [62, 48, 78, 40, 66, 54, 84, 46, 70, 36, 58, 74, 44, 60]
        : [52, 78, 40, 88, 60, 34];
@endphp

<x-filament::grid.column
    :default="$columnSpan['default'] ?? 1"
    :sm="$columnSpan['sm'] ?? null"
    :md="$columnSpan['md'] ?? null"
    :lg="$columnSpan['lg'] ?? null"
    :xl="$columnSpan['xl'] ?? null"
    :twoXl="$columnSpan['2xl'] ?? null"
    :defaultStart="$columnStart['default'] ?? null"
    :smStart="$columnStart['sm'] ?? null"
    :mdStart="$columnStart['md'] ?? null"
    :lgStart="$columnStart['lg'] ?? null"
    :xlStart="$columnStart['xl'] ?? null"
    :twoXlStart="$columnStart['2xl'] ?? null"
    class="fi-wi-chart colisap-chart-skeleton"
    aria-busy="true"
>
    <x-filament::section :heading="$heading" :description="$description">
        <span class="sr-only">Loading chart…</span>

        @if ($isRadial)
            <div class="colisap-skel-chart colisap-skel-chart--radial" style="height: {{ $height }}; --chart-h: {{ $height }}" aria-hidden="true">
                <span class="colisap-skel colisap-skel-donut"></span>
                <div class="colisap-skel-legend">
                    @foreach (['3.5rem', '4.25rem', '3rem', '4rem'] as $width)
                        <span><i class="colisap-skel" style="--w: .45rem; --h: .45rem; --r: 9999px"></i><i class="colisap-skel" style="--w: {{ $width }}; --h: .55rem"></i></span>
                    @endforeach
                </div>
            </div>
        @else
            <div class="colisap-skel-chart" style="height: {{ $height }}" aria-hidden="true">
                @foreach ($barHeights as $barHeight)
                    <span class="colisap-skel" style="--h: {{ $barHeight }}%"></span>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament::grid.column>
