<?php

namespace App\Filament\Widgets;

use App\Models\Claim;
use App\Models\Contribution;
use App\Models\Member;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ColisapStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $activeMembers = Member::where('status', 'active')->count();

        $contributionsThisMonth = Contribution::whereMonth('contribution_date', now()->month)
            ->whereYear('contribution_date', now()->year)
            ->sum('amount');

        $pendingClaims = Claim::whereIn('status', ['submitted', 'under_review'])->count();

        $paidOutYtd = Claim::where('status', 'paid')
            ->whereYear('paid_at', now()->year)
            ->sum('approved_amount');

        return [
            Stat::make('Active Members', number_format($activeMembers))
                ->description('Currently active COLISAP members')
                ->color('success'),
            Stat::make('Contributions This Month', '₱'.number_format($contributionsThisMonth, 2))
                ->description(now()->format('F Y'))
                ->color('primary'),
            Stat::make('Pending Claims', number_format($pendingClaims))
                ->description('Submitted or under review')
                ->color('warning'),
            Stat::make('Paid Out YTD', '₱'.number_format($paidOutYtd, 2))
                ->description(now()->format('Y').' death benefit payouts')
                ->color('info'),
        ];
    }
}
