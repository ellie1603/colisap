<?php

namespace App\Filament\Pages;

use App\Services\Policy\PolicyDefaults;
use App\Services\Policy\PolicySettings as PolicySettingsService;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * Super Admin editing of every configurable COLISAP policy value. Each change is audit-logged.
 */
class PolicySettings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Policy Settings';

    protected static string $view = 'filament.pages.policy-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->can('policy.manage') ?? false;
    }

    public function mount(PolicySettingsService $policy): void
    {
        $this->form->fill($policy->all());
    }

    public function form(Form $form): Form
    {
        $sections = collect(PolicyDefaults::SETTINGS)
            ->groupBy('group', preserveKeys: true)
            ->map(fn ($settings, $group) => Forms\Components\Section::make($group)
                ->columns(['default' => 1, 'md' => 2])
                ->collapsible()
                ->schema($settings->map(fn (array $setting, string $key) => $this->field($key, $setting))->values()->all()))
            ->values()
            ->all();

        return $form->schema($sections)->statePath('data');
    }

    /**
     * @param  array{label: string, type: string, help?: string, options?: array<string, string>}  $setting
     */
    private function field(string $key, array $setting): Forms\Components\Field
    {
        $field = match (true) {
            isset($setting['options']) => Forms\Components\Select::make($key)->options($setting['options'])->required(),
            $setting['type'] === 'bool' => Forms\Components\Toggle::make($key),
            $setting['type'] === 'int' => Forms\Components\TextInput::make($key)->integer()->minValue(0)->required(),
            default => Forms\Components\TextInput::make($key)->numeric()->minValue(0)->required(),
        };

        if (str_contains($key, 'coop_share')) {
            $field->maxValue(100)->suffix('%');
        } elseif (str_starts_with($key, 'benefit_') || str_starts_with($key, 'min_balance') || in_array($key, ['claim_contribution', 'additional_60k_contribution', 'reapplication_fee'], true)) {
            $field->prefix('₱');
        }

        return $field->label($setting['label'])->helperText($setting['help'] ?? null);
    }

    public function save(PolicySettingsService $policy): void
    {
        $data = $this->form->getState();

        if (($data['max_beneficiaries'] ?? 0) < ($data['min_beneficiaries'] ?? 0)) {
            Notification::make()->title('Maximum beneficiaries must be at least the minimum')->danger()->send();

            return;
        }

        $policy->update($data);

        Notification::make()->title('Policy settings saved')
            ->body('Use "Apply to all members now" to recalculate statuses immediately, or wait for the nightly run.')
            ->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('apply')
                ->label('Apply to all members now')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Re-evaluates every participating member against the current policy (waiting period, dormancy, replenishment, terminations, upgrades).')
                ->action(function () {
                    Artisan::call('colisap:update-member-statuses');

                    Notification::make()->title('Statuses recalculated')->body(trim(Artisan::output()))->success()->send();
                }),
        ];
    }
}
