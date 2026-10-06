<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\MemberResource;
use App\Models\Member;
use App\Services\Colisap\MonitoringService;
use App\Services\Policy\PolicySettings;
use Filament\Widgets\Widget;

class MemberStatusStats extends Widget
{
    protected static string $view = 'filament.widgets.member-status-stats';

    protected static ?string $pollingInterval = '60s';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $monitoring = app(MonitoringService::class);
        $policy = app(PolicySettings::class);
        $counts = $monitoring->statusCounts();
        $total = array_sum($counts);
        $window = $policy->int('alert_window_days');
        $dormancyWarning = $policy->int('dormancy_warning_days');
        $percentOf = fn (int $value): int => $total > 0 ? (int) round($value / $total * 100) : 0;
        $url = fn (?string $status = null): string => MemberResource::getUrl('index', $status ? ['activeTab' => $status] : []);

        return [
            'total' => $total,
            'totalUrl' => $url(),
            'breakdown' => collect(Member::STATUSES)->map(fn (string $label, string $status) => [
                'key' => $status,
                'label' => $label,
                'count' => $counts[$status],
                'percent' => $total > 0 ? $counts[$status] / $total * 100 : 0,
            ])->values()->all(),
            'cards' => [
                [
                    'key' => 'active',
                    'label' => 'Active',
                    'icon' => 'heroicon-o-check-badge',
                    'count' => $counts['active'],
                    'percent' => $percentOf($counts['active']),
                    'insight' => 'Effective participants',
                    'url' => $url('active'),
                ],
                [
                    'key' => 'waiting',
                    'label' => 'Waiting',
                    'icon' => 'heroicon-o-clock',
                    'count' => $counts['waiting'],
                    'percent' => $percentOf($counts['waiting']),
                    'insight' => number_format($monitoring->becomingEffectiveWithin($window)->count())." effective within {$window} days",
                    'url' => $url('waiting'),
                ],
                [
                    'key' => 'dormant',
                    'label' => 'Dormant',
                    'icon' => 'heroicon-o-pause-circle',
                    'count' => $counts['dormant'],
                    'percent' => $percentOf($counts['dormant']),
                    'insight' => number_format($monitoring->dormancyApproachingTermination($dormancyWarning)->count())." near termination ({$dormancyWarning} days)",
                    'url' => $url('dormant'),
                ],
            ],
        ];
    }
}
