<?php

namespace App\Filament\Widgets;

class BranchDistributionChart extends ColisapChart
{
    protected static ?string $heading = 'Members per branch';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $values = collect($this->monitoring()->statusByBranch())->map(fn (array $counts) => array_sum($counts))->all();

        return $this->single('Members', $values, array_fill(0, count($values), '#3B3FA6'));
    }
}
