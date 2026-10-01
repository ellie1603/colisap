<?php

namespace App\Filament\Widgets;

use App\Services\Colisap\MonitoringService;
use Filament\Widgets\Widget;

class AlertsWidget extends Widget
{
    protected static string $view = 'filament.widgets.alerts';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '60s';

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
