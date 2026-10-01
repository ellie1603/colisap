<?php

namespace App\Filament\Widgets;

class SegmentationChart extends ColisapChart
{
    protected static ?string $heading = 'Segmentation';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $values = $this->monitoring()->segmentCounts();

        return $this->single('Participants', $values, ['#3B3FA6', '#F59E0B', '#94A3B8', '#0EA5E9', '#E5E7EB']);
    }
}
