<x-filament-panels::page>
    @php($counts = $this->tabCounts())

    <x-filament::tabs label="Monitoring views">
        @foreach (\App\Filament\Pages\Monitoring::TABS as $key => [$label, $icon])
            <x-filament::tabs.item
                :active="$tab === $key"
                :icon="$icon"
                :badge="$counts[$key] ?: null"
                wire:click="switchTab('{{ $key }}')"
            >
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-panels::page>
