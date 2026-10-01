<?php

namespace App\Filament\Widgets;

use App\Models\Member;

class StatusByBranchChart extends ColisapChart
{
    protected static ?string $heading = 'Status by branch';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $byBranch = $this->monitoring()->statusByBranch();

        return [
            'datasets' => collect(Member::STATUSES)->map(fn (string $label, string $status) => [
                'label' => $label,
                'data' => array_values(array_map(fn (array $counts) => $counts[$status] ?? 0, $byBranch)),
                'backgroundColor' => self::STATUS_COLORS[$label],
                'borderWidth' => 0,
            ])->values()->all(),
            'labels' => array_keys($byBranch),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
            'maintainAspectRatio' => false,
        ];
    }
}
