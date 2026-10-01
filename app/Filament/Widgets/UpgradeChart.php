<?php

namespace App\Filament\Widgets;

class UpgradeChart extends ColisapChart
{
    protected static ?string $heading = '90-day upgrade monitoring';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        return $this->single('40K members', $this->monitoring()->upgradeSummary(), ['#10B981', '#0EA5E9', '#94A3B8']);
    }
}
