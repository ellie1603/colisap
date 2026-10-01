<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Monitoring;
use App\Services\Colisap\MonitoringService;
use App\Services\Policy\PolicySettings;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MonitoringStats extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $monitoring = app(MonitoringService::class);
        $window = app(PolicySettings::class)->int('alert_window_days');
        $categories = $monitoring->categoryCounts();
        $segments = $monitoring->segmentCounts();

        return [
            Stat::make('40K / 60K participants', number_format($categories['40K']).' / '.number_format($categories['60K']))
                ->description('Benefit category')
                ->icon('heroicon-o-shield-check'),
            Stat::make('Diamond · Gold · Silver · Regular', implode(' · ', array_map('number_format', [$segments['Diamond'], $segments['Gold'], $segments['Silver'], $segments['Regular']])))
                ->description(number_format($segments['Unassigned']).' unassigned')
                ->icon('heroicon-o-sparkles'),
            Stat::make('Without beneficiaries', number_format($monitoring->withoutBeneficiaries()->count()))
                ->description('Beneficiary information incomplete')
                ->color('danger')
                ->icon('heroicon-o-user-minus')
                ->url(Monitoring::getUrl(['tab' => 'beneficiaries'])),
            Stat::make('Need replenishment', number_format($monitoring->belowMinimumBalance()->count()))
                ->description('Savings below maintaining balance')
                ->color('warning')
                ->icon('heroicon-o-bell-alert')
                ->url(Monitoring::getUrl(['tab' => 'replenishment'])),
            Stat::make("Effective within {$window} days", number_format($monitoring->becomingEffectiveWithin($window)->count()))
                ->description('Waiting period ending')
                ->color('info')
                ->icon('heroicon-o-clock')
                ->url(Monitoring::getUrl(['tab' => 'effectivity'])),
            Stat::make('Eligible for 60K upgrade', number_format($monitoring->upgradeQuery('eligible')->count()))
                ->description('90-day wait completed')
                ->color('success')
                ->icon('heroicon-o-arrow-trending-up')
                ->url(Monitoring::getUrl(['tab' => 'upgrades'])),
        ];
    }
}
