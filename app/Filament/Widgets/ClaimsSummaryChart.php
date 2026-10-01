<?php

namespace App\Filament\Widgets;

class ClaimsSummaryChart extends ColisapChart
{
    protected static ?string $heading = 'Mortuary claims';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        return $this->single('Claims', $this->monitoring()->claimSummary(), ['#94A3B8', '#F59E0B', '#10B981', '#0EA5E9', '#E11D48']);
    }
}
