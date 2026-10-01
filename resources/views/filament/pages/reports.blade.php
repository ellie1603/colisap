<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Generate a report</x-slot>
        <x-slot name="description">All reports read live data from the member database. Excel/CSV exports include every matching record; PDF is best for printing shorter lists.</x-slot>

        <div class="colisap-stack">
            {{ $this->form }}

            <div class="colisap-actions">
                <x-filament::button wire:click="export('xlsx')" icon="heroicon-o-table-cells" wire:loading.attr="disabled">Excel (.xlsx)</x-filament::button>
                <x-filament::button wire:click="export('csv')" icon="heroicon-o-document-text" color="gray" wire:loading.attr="disabled">CSV</x-filament::button>
                <x-filament::button wire:click="export('pdf')" icon="heroicon-o-printer" color="gray" wire:loading.attr="disabled">PDF</x-filament::button>
                <x-filament::loading-indicator class="h-5 w-5" wire:loading wire:target="export" />
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
