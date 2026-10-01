<?php

namespace App\Filament\Pages;

use App\Filament\Widgets;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * COLISAP monitoring dashboard — every figure is a live aggregate of the member database.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'COLISAP Monitoring Dashboard';

    public function getColumns(): int|string|array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 3];
    }

    public function getWidgets(): array
    {
        return [
            Widgets\DashboardHeader::class,
            Widgets\MemberStatusStats::class,
            Widgets\MonitoringStats::class,
            Widgets\AlertsWidget::class,
            Widgets\MemberStatusChart::class,
            Widgets\SegmentationChart::class,
            Widgets\CategoryChart::class,
            Widgets\BranchDistributionChart::class,
            Widgets\StatusByBranchChart::class,
            Widgets\MonthlyTrendChart::class,
            Widgets\EffectivityChart::class,
            Widgets\UpgradeChart::class,
            Widgets\ReplenishmentChart::class,
            Widgets\BeneficiaryComplianceChart::class,
            Widgets\ClaimsSummaryChart::class,
        ];
    }
}
