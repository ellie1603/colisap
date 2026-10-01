<?php

namespace App\Filament\Widgets;

class MonthlyTrendChart extends ColisapChart
{
    protected static ?string $heading = 'New approvals — last 12 months';

    protected int|string|array $columnSpan = ['default' => 1, 'xl' => 2];

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $values = $this->monitoring()->monthlyTrend();

        return ['datasets' => [['label' => 'Approved', 'data' => array_values($values), 'borderColor' => '#3B3FA6', 'backgroundColor' => 'rgba(59, 63, 166, 0.15)', 'fill' => true, 'tension' => 0.3]], 'labels' => array_keys($values)];
    }
}
