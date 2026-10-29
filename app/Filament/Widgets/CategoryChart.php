<?php

namespace App\Filament\Widgets;

use Livewire\Attributes\Lazy;

#[Lazy(isolate: false)]
class CategoryChart extends ColisapChart
{
    protected static ?string $heading = 'Benefit category';

    protected static ?string $description = 'Participants by benefit category.';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        return $this->single('Participants', $this->monitoring()->categoryCounts(), [self::INDIGO, self::ORANGE]);
    }
}
