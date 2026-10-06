<?php

namespace App\Filament\Widgets;

use App\Services\Colisap\MonitoringService;
use Filament\Widgets\ChartWidget;

/**
 * Shared look for dashboard charts: brand palette per status/segment. Charts load lazily and do not poll, to stay light on older PCs.
 */
abstract class ColisapChart extends ChartWidget
{
    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '260px';

    public const INDIGO = '#2E3A8C';

    public const SKY = '#1E88C8';

    public const YELLOW = '#F5B800';

    public const ORANGE = '#E8691C';

    public const GREEN = '#12A27B';

    public const ROSE = '#E11D48';

    public const SLATE = '#64748B';

    public const MIST = '#CBD5E1';

    public const STATUS_COLORS = [
        'Waiting' => self::SKY,
        'Active' => self::GREEN,
        'Dormant' => self::YELLOW,
        'Deceased' => self::SLATE,
        'Terminated' => self::ROSE,
        'Withdrawn' => '#B4ADA5',
    ];

    public const SERIES = [self::INDIGO, self::ORANGE, self::SKY, self::YELLOW, self::GREEN, '#8B5CF6', self::SLATE, self::ROSE];

    protected function monitoring(): MonitoringService
    {
        return app(MonitoringService::class);
    }

    protected function isRadial(): bool
    {
        return in_array($this->getType(), ['doughnut', 'pie'], true);
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
                ...$this->datasetStyle(),
            ]],
            'labels' => array_keys($values),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function datasetStyle(): array
    {
        return $this->isRadial()
            ? ['borderWidth' => 0, 'spacing' => 3, 'borderRadius' => 6, 'hoverOffset' => 8]
            : ['borderWidth' => 0, 'borderRadius' => 8, 'borderSkipped' => false, 'maxBarThickness' => 38];
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'animation' => ['duration' => 600, 'easing' => 'easeOutQuart'],
            'layout' => ['padding' => ['top' => 4]],
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                    'labels' => ['usePointStyle' => true, 'pointStyle' => 'circle', 'boxWidth' => 7, 'boxHeight' => 7, 'padding' => 16, 'font' => ['size' => 12]],
                ],
                'tooltip' => [
                    'backgroundColor' => '#161C52',
                    'titleColor' => '#FFFFFF',
                    'bodyColor' => '#E2E8F0',
                    'padding' => 12,
                    'cornerRadius' => 10,
                    'boxPadding' => 6,
                    'usePointStyle' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function cartesianScales(bool $stacked = false): array
    {
        return [
            'x' => ['stacked' => $stacked, 'grid' => ['display' => false], 'border' => ['display' => false], 'ticks' => ['color' => '#94A3B8', 'font' => ['size' => 11]]],
            'y' => ['stacked' => $stacked, 'beginAtZero' => true, 'grid' => ['color' => 'rgba(148, 163, 184, 0.16)'], 'border' => ['display' => false], 'ticks' => ['precision' => 0, 'color' => '#94A3B8', 'font' => ['size' => 11], 'padding' => 8]],
        ];
    }

    protected function getOptions(): array
    {
        $options = $this->baseOptions();

        if ($this->isRadial()) {
            $options['cutout'] = '72%';

            return $options;
        }

        $options['plugins']['legend']['display'] = false;
        $options['scales'] = $this->cartesianScales();

        return $options;
    }
}
