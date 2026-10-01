<?php

namespace App\Filament\Widgets;

class EffectivityChart extends ColisapChart
{
    protected static ?string $heading = '180-day effectivity';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        return $this->single('Waiting members', $this->monitoring()->effectivityBuckets(), ['#10B981', '#0EA5E9', '#3B3FA6', '#94A3B8']);
    }
}
