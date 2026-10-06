<?php

namespace App\Filament\Widgets;

class SegmentationChart extends ColisapChart
{
    protected static ?string $heading = 'Segmentation';

    protected static ?string $description = 'Participating members by tier.';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $values = $this->monitoring()->segmentCounts();

        return $this->single('Participants', $values, [self::INDIGO, self::YELLOW, '#94A3B8', self::SKY, '#E2E8F0']);
    }
}
