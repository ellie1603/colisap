<?php

namespace App\Filament\Widgets;

class CategoryChart extends ColisapChart
{
    protected static ?string $heading = 'Benefit category';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        return $this->single('Participants', $this->monitoring()->categoryCounts(), ['#3B3FA6', '#F2711C']);
    }
}
