{{-- Sits beside the filter button of every table that has filters: puts all filters back to how the table opens. --}}
<x-filament::icon-button
    icon="heroicon-m-arrow-path"
    color="gray"
    label="Reset filters"
    tooltip="Reset filters"
    wire:click="resetTableFiltersForm"
    wire:loading.attr="disabled"
    wire:target="resetTableFiltersForm"
/>
