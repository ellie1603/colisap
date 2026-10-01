<?php

namespace App\Filament\Widgets;

use App\Services\Colisap\MonitoringService;
use Filament\Widgets\ChartWidget;

/**
 * Shared look for dashboard charts: consistent colours per status/segment, live data, gentle polling.
 */
abstract class ColisapChart extends ChartWidget
{
    protected static ?string $pollingInterval = '60s';

    protected static ?string $maxHeight = '260px';

    public const STATUS_COLORS = [
        'Waiting' => '#0EA5E9',
        'Active' => '#10B981',
        'Dormant' => '#F59E0B',
        'Deceased' => '#64748B',
        'Terminated' => '#E11D48',
        'Withdrawn' => '#A8A29E',
    ];

    public const SERIES = ['#3B3FA6', '#F2711C', '#10B981', '#0EA5E9', '#E11D48', '#F59E0B', '#64748B', '#8B5CF6'];

    protected function monitoring(): MonitoringService
    {
        return app(MonitoringService::class);
    }

    /**
     * @param  array<string, int|float>  $values
     * @param  list<string>|null  $colors
     * @return array<string, mixed>
     */
    protected function single(string $label, array $values, ?array $colors = null): array
    {
        return [
            'datasets' => [[
                'label' => $label,
                'data' => array_values($values),
                'backgroundColor' => $colors ?? array_slice(array_merge(self::SERIES, self::SERIES), 0, count($values)),
                'borderWidth' => 0,
            ]],
            'labels' => array_keys($values),
        ];
    }

    protected function getOptions(): array
    {
        return in_array($this->getType(), ['doughnut', 'pie'], true)
            ? ['plugins' => ['legend' => ['position' => 'bottom']], 'maintainAspectRatio' => false]
            : [
                'plugins' => ['legend' => ['display' => false]],
                'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
                'maintainAspectRatio' => false,
            ];
    }
}
