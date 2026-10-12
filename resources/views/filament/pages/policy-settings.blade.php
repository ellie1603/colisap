<x-filament-panels::page>
    <div class="colisap-policy-note" role="note">
        <strong>Policy notes.</strong>
        Every amount and period below can be changed when the policy changes. The two benefit categories are named after their benefit amounts, so the whole system follows what is set here.
        "Activity" for dormancy is not defined precisely in the policy; choose the source under Dormancy.
    </div>

    <form wire:submit="save" class="colisap-stack">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit" icon="heroicon-o-check">Save policy settings</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
