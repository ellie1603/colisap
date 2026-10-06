<?php

namespace App\Filament\Widgets;

class UpgradeChart extends ColisapChart
{
    protected static ?string $heading = '90-day upgrade monitoring';

    protected static ?string $description = '40K members on the 90-day upgrade path.';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        return $this->single('40K members', $this->monitoring()->upgradeSummary(), [self::GREEN, self::SKY, self::MIST]);
    }
}
