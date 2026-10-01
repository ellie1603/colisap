<?php

namespace App\Filament\Widgets;

use App\Services\Policy\PolicySettings;
use Filament\Widgets\Widget;

class DashboardHeader extends Widget
{
    protected static string $view = 'filament.widgets.dashboard-header';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $policy = app(PolicySettings::class);

        return [
            'user' => auth()->user(),
            'rules' => [
                $policy->int('effectivity_days').'-day waiting period',
                $policy->int('upgrade_wait_days').'-day 60K upgrade',
                'Min. balance ₱'.number_format($policy->decimal('min_balance_40k')).' / ₱'.number_format($policy->decimal('min_balance_60k')),
                $policy->int('replenishment_days').'-day replenishment',
                $policy->int('dormancy_months').'-month dormancy',
            ],
        ];
    }
}
