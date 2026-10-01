<?php

namespace App\Filament\Widgets;

class ReplenishmentChart extends ColisapChart
{
    protected static ?string $heading = 'Replenishment monitoring';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        return $this->single('Members', $this->monitoring()->replenishmentSummary(), ['#F59E0B', '#F2711C', '#E11D48', '#94A3B8']);
    }
}
