<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\MemberResource;
use App\Models\Member;
use App\Services\Colisap\MonitoringService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MemberStatusStats extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $counts = app(MonitoringService::class)->statusCounts();
        $url = fn (?string $status = null) => MemberResource::getUrl('index', $status ? ['activeTab' => $status] : []);

        return [
            Stat::make('Total members', number_format(array_sum($counts)))
                ->description('All COLISAP records')
                ->icon('heroicon-o-users')
                ->url($url()),
            ...collect(Member::STATUSES)->map(fn (string $label, string $status) => Stat::make($label, number_format($counts[$status]))
                ->icon(Member::statusIcon($status))
                ->color(Member::statusColor($status))
                ->description(match ($status) {
                    'waiting' => 'Within the waiting period',
                    'active' => 'Effective participants',
                    'dormant' => 'Savings account dormant',
                    'deceased' => 'Records kept for claims',
                    'terminated' => 'Per policy VII',
                    default => 'Voluntary withdrawal',
                })
                ->url($url($status)))->values()->all(),
        ];
    }
}
