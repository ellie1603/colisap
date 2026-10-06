<?php

namespace App\Filament\Pages;

use App\Filament\Widgets;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * COLISAP monitoring dashboard — every figure is a live aggregate of the member database.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'COLISAP Monitoring Dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getColumns(): int|string|array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 3];
    }

    public function getWidgets(): array
    {
        return [
            Widgets\DashboardHeader::class,
            Widgets\MemberStatusStats::class,
            Widgets\MonthlyTrendChart::class,
            Widgets\AlertsWidget::class,
            Widgets\MemberStatusChart::class,
            Widgets\SegmentationChart::class,
            Widgets\CategoryChart::class,
            Widgets\StatusByBranchChart::class,
            Widgets\EffectivityChart::class,
            Widgets\UpgradeChart::class,
            Widgets\ReplenishmentChart::class,
        ];
    }
}
