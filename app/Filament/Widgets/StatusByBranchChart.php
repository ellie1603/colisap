<?php

namespace App\Filament\Widgets;

use App\Models\Branch;
use App\Models\Member;

class StatusByBranchChart extends ColisapChart
{
    protected static ?string $heading = 'Status by branch';

    protected static ?string $description = 'Member status across all branches.';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '320px';

    /**
     * A branch comparison has nothing to compare for staff limited to their own branch.
     */
    public static function canView(): bool
    {
        return Branch::restrictedId() === null;
    }

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
                'borderRadius' => 4,
                'maxBarThickness' => 28,
            ])->values()->all(),
            'labels' => array_keys($byBranch),
        ];
    }

    protected function getOptions(): array
    {
        $options = $this->baseOptions();
        $options['scales'] = $this->cartesianScales(stacked: true);
        $options['interaction'] = ['mode' => 'index', 'intersect' => false];

        return $options;
    }
}
