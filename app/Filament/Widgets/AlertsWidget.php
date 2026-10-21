<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Monitoring;
use App\Services\Colisap\MonitoringService;
use Filament\Widgets\Widget;

class AlertsWidget extends Widget
{
    protected static string $view = 'filament.widgets.alerts';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    protected static ?string $pollingInterval = '60s';

    /**
     * Every alert opens a Monitoring tab, so only staff who can open Monitoring see them.
     */
    public static function canView(): bool
    {
        return Monitoring::canAccess();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'alerts' => app(MonitoringService::class)->alerts(),
        ];
    }
}
