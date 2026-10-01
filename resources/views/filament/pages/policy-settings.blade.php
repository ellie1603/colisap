<x-filament-panels::page>
    <div class="colisap-policy-note" role="note">
        <strong>Policy notes.</strong>
        The written policy mentions ₱50,000 once in the maintaining-balance section while every other section uses ₱60,000 — ₱60,000 is used.
        "Activity" for dormancy is not defined precisely in the policy; choose the source under Dormancy.
        The 1–3 beneficiary rule is a system requirement, not part of the written policy.
    </div>

    <form wire:submit="save" class="colisap-stack">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit" icon="heroicon-o-check">Save policy settings</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
