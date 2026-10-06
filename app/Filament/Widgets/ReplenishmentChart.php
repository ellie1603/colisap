<?php

namespace App\Filament\Widgets;

class ReplenishmentChart extends ColisapChart
{
    protected static ?string $heading = 'Replenishment monitoring';

    protected static ?string $description = 'Notices and members below minimum balance.';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        return $this->single('Members', $this->monitoring()->replenishmentSummary(), [self::YELLOW, self::ORANGE, self::ROSE, self::MIST]);
    }
}
