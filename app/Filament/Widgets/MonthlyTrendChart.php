<?php

namespace App\Filament\Widgets;

class MonthlyTrendChart extends ColisapChart
{
    protected static ?string $heading = 'New approvals';

    protected static ?string $description = 'Members approved per month, last 12 months.';

    protected static ?string $maxHeight = '300px';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = ['default' => 1, 'md' => 2];

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $values = $this->monitoring()->monthlyTrend();

        return [
            'datasets' => [[
                'label' => 'Approved',
                'data' => array_values($values),
                'borderColor' => self::INDIGO,
                'backgroundColor' => 'rgba(46, 58, 140, 0.10)',
                'borderWidth' => 2.5,
                'fill' => true,
                'tension' => 0.4,
                'pointRadius' => 0,
                'pointHoverRadius' => 6,
                'pointHoverBackgroundColor' => self::ORANGE,
                'pointHoverBorderColor' => '#FFFFFF',
                'pointHoverBorderWidth' => 3,
            ]],
            'labels' => array_keys($values),
        ];
    }

    protected function getOptions(): array
    {
        $options = parent::getOptions();
        $options['interaction'] = ['mode' => 'index', 'intersect' => false];

        return $options;
    }
}
