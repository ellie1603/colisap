<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Monitoring;
use App\Filament\Resources\MemberResource;
use App\Models\Branch;
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
        $hour = now()->hour;

        return [
            'greeting' => match (true) {
                $hour < 12 => 'Good morning',
                $hour < 18 => 'Good afternoon',
                default => 'Good evening',
            },
            'firstName' => str(auth()->user()?->name)->before(' ')->toString(),
            'today' => now()->format('l, j F Y'),
            'ownBranchName' => Branch::whereKey(Branch::restrictedId())->value('name'),
            'rules' => [
                $policy->int('effectivity_days').'-day waiting period',
                $policy->int('upgrade_wait_days').'-day 60K upgrade',
                'Min. balance ₱'.number_format($policy->decimal('min_balance_40k')).' / ₱'.number_format($policy->decimal('min_balance_60k')),
                $policy->int('replenishment_days').'-day replenishment',
                $policy->int('dormancy_months').'-month dormancy',
            ],
            'actions' => array_filter([
                MemberResource::canCreate() ? ['label' => 'Add member', 'icon' => 'heroicon-m-user-plus', 'url' => MemberResource::getUrl('create'), 'primary' => true] : null,
                ['label' => 'Import Excel', 'icon' => 'heroicon-m-arrow-up-tray', 'url' => MemberResource::getUrl('import'), 'primary' => false],
                Monitoring::canAccess() ? ['label' => 'Monitoring', 'icon' => 'heroicon-m-eye', 'url' => Monitoring::getUrl(), 'primary' => false] : null,
            ]),
        ];
    }
}
