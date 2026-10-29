<?php

namespace App\Filament\Widgets;

use Livewire\Attributes\Lazy;

#[Lazy(isolate: false)]
class EffectivityChart extends ColisapChart
{
    protected static ?string $heading = '180-day effectivity';

    protected static ?string $description = 'Waiting members by days to effectivity.';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        return $this->single('Waiting members', $this->monitoring()->effectivityBuckets(), [self::GREEN, self::SKY, self::INDIGO, self::MIST]);
    }
}
