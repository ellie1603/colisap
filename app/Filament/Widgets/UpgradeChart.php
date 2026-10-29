<?php

namespace App\Filament\Widgets;

use App\Models\Member;
use Livewire\Attributes\Lazy;

#[Lazy(isolate: false)]
class UpgradeChart extends ColisapChart
{
    protected static ?string $heading = '90-day upgrade monitoring';

    protected static ?string $description = 'Members waiting out the upgrade period.';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        return $this->single(Member::basicCategoryLabel().' members', $this->monitoring()->upgradeSummary(), [self::GREEN, self::SKY, self::MIST]);
    }
}
