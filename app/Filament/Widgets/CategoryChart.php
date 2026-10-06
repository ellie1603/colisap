<?php

namespace App\Filament\Widgets;

class CategoryChart extends ColisapChart
{
    protected static ?string $heading = 'Benefit category';

    protected static ?string $description = '40K and 60K participants.';

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
