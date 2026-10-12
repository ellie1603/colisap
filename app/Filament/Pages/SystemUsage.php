<?php

namespace App\Filament\Pages;

use App\Services\Reports\StaffUsageReport;
use Filament\Actions;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Developer-only report for management: which staff accounts are really using the system in a period.
 * The cooperative's own Administrator does not see it.
 */
class SystemUsage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'System Usage';

    protected static ?string $title = 'System Usage';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.system-usage';

    #[Url]
    public string $period = 'this_week';

    public static function canAccess(): bool
    {
        return Auth::user()?->isDeveloper() ?? false;
    }

    public function mount(): void
    {
        if (! array_key_exists($this->period, StaffUsageReport::PERIODS)) {
            $this->period = 'this_week';
        }
    }

    public function switchPeriod(string $period): void
    {
        if (array_key_exists($period, StaffUsageReport::PERIODS)) {
            $this->period = $period;
            unset($this->report);
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function report(): array
    {
        return app(StaffUsageReport::class)->build($this->period);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => app(StaffUsageReport::class)->download($this->period)),
        ];
    }
}
