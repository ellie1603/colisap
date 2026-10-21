<?php

namespace App\Filament\Pages;

use App\Models\MonitoringApiKey;
use App\Models\MonitoringApiLog;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * Super admin (developer account) page for the BMPC ONE Monitoring API (UNIVERSAL_BMPC_MONITORING_INTEGRATION.md §13):
 * generate, disable, rotate and revoke keys, see when they were last used and recent requests.
 * A new key is shown once, on this page only; it is never stored, so it cannot be revealed later.
 */
class MonitoringApiKeys extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Monitoring API';

    protected static ?string $title = 'BMPC ONE Monitoring API';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'filament.pages.monitoring-api-keys';

    /**
     * The key just generated, shown once. Kept only in this page's state, never in the database or session.
     */
    public ?string $newKey = null;

    public ?string $newKeyName = null;

    /**
     * Only the developer (super admin) account manages the API keys; the coop's own Administrator does not see this page.
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return ($user?->isDeveloper() ?? false) && $user->can('monitoring_api.manage');
    }

    public function dismissNewKey(): void
    {
        $this->newKey = null;
        $this->newKeyName = null;
    }

    /**
     * @return Collection<int, MonitoringApiLog>
     */
    #[Computed]
    public function recentRequests(): Collection
    {
        return MonitoringApiLog::query()->with('apiKey:id,name')->latest('id')->limit(15)->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('generate')
                ->label('Generate API key')
                ->icon('heroicon-o-key')
                ->modalDescription('Give the key to BMPC ONE together with this system\'s address. It is shown only once.')
                ->form([
                    Forms\Components\TextInput::make('name')->label('Name')->default('BMPC ONE')->required()->maxLength(100),
                    Forms\Components\CheckboxList::make('scopes')->label('Allowed information')
                        ->options(MonitoringApiKey::SCOPES)->default(array_keys(MonitoringApiKey::SCOPES))->required(),
                    Forms\Components\DatePicker::make('expires_at')->label('Expires on')->minDate(now()->addDay())
                        ->helperText('Leave blank for a key that does not expire. You can revoke it at any time.'),
                ])
                ->action(function (array $data) {
                    [$key, $raw] = MonitoringApiKey::generate($data['name'], $data['scopes'], $data['expires_at'] ? Carbon::parse($data['expires_at'])->endOfDay() : null);
                    $this->showNewKey($key, $raw);
                }),
        ];
    }

    private function showNewKey(MonitoringApiKey $key, string $raw): void
    {
        $this->newKey = $raw;
        $this->newKeyName = $key->name;

        Notification::make()->title('API key generated')->body('Copy it now — it will not be shown again.')->success()->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(MonitoringApiKey::query()->with('creator:id,name'))
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight('bold')
                    ->description(fn (MonitoringApiKey $record) => $record->maskedKey()),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->state(fn (MonitoringApiKey $record) => $record->unusableReason() === 'expired' ? 'expired' : $record->status)
                    ->formatStateUsing(fn (string $state) => MonitoringApiKey::STATUSES[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success', 'disabled' => 'warning', default => 'danger'
                    }),
                Tables\Columns\TextColumn::make('scopes')->badge()->color('gray')->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('last_used_at')->label('Last used')->since()->placeholder('Never'),
                Tables\Columns\TextColumn::make('expires_at')->label('Expires')->date()->placeholder('Never')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('created_at')->label('Created')->date()
                    ->description(fn (MonitoringApiKey $record) => $record->creator?->name)->visibleFrom('md'),
            ])
            ->actions([
                Tables\Actions\Action::make('disable')
                    ->label('Disable')->icon('heroicon-m-pause')->color('warning')
                    ->visible(fn (MonitoringApiKey $record) => $record->status === 'active')
                    ->requiresConfirmation()->modalDescription('BMPC ONE cannot use this key until you enable it again.')
                    ->action(fn (MonitoringApiKey $record) => $record->update(['status' => 'disabled'])),
                Tables\Actions\Action::make('enable')
                    ->label('Enable')->icon('heroicon-m-play')->color('success')
                    ->visible(fn (MonitoringApiKey $record) => $record->status === 'disabled')
                    ->action(fn (MonitoringApiKey $record) => $record->update(['status' => 'active'])),
                Tables\Actions\Action::make('rotate')
                    ->label('Rotate')->icon('heroicon-m-arrow-path')->color('gray')
                    ->visible(fn (MonitoringApiKey $record) => $record->status !== 'revoked')
                    ->requiresConfirmation()
                    ->modalDescription('Makes a new key with the same settings and revokes this one at once. Give BMPC ONE the new key right away.')
                    ->action(function (MonitoringApiKey $record) {
                        [$key, $raw] = $record->rotate();
                        $this->showNewKey($key, $raw);
                    }),
                Tables\Actions\Action::make('revoke')
                    ->label('Revoke')->icon('heroicon-m-no-symbol')->color('danger')
                    ->visible(fn (MonitoringApiKey $record) => $record->status !== 'revoked')
                    ->requiresConfirmation()->modalDescription('The key stops working permanently. This cannot be undone.')
                    ->action(fn (MonitoringApiKey $record) => $record->revoke()),
            ])
            ->emptyStateHeading('No API keys yet')
            ->emptyStateDescription('Generate a key when BMPC ONE is ready to connect to COLISAP.')
            ->defaultSort('created_at', 'desc')
            ->paginated(false);
    }
}
