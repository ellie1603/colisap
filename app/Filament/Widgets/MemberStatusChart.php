<?php

namespace App\Filament\Widgets;

use App\Models\Member;

class MemberStatusChart extends ColisapChart
{
    protected static ?string $heading = 'Member status';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $counts = $this->monitoring()->statusCounts();
        $values = collect(Member::STATUSES)->mapWithKeys(fn ($label, $status) => [$label => $counts[$status]])->all();

        return $this->single('Members', $values, array_values(array_intersect_key(self::STATUS_COLORS, $values)));
    }
}
