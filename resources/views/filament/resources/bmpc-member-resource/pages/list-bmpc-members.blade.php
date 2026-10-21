<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    <div class="flex flex-col gap-y-6">
        @if ($importFile)
            @php
                $done = $importSheetCount - count($importSheets);
                $percent = $importSheetCount ? (int) floor($done / $importSheetCount * 100) : 0;
            @endphp
            <x-filament::section wire:poll.500ms="importNextSheet">
                <x-slot name="heading">Importing BMPC members…</x-slot>
                <div class="colisap-stack" role="status" aria-live="polite">
                    <div class="colisap-progress"><div class="colisap-progress__bar" style="width: {{ $percent }}%"></div></div>
                    <p class="colisap-muted">
                        {{ $done }} of {{ $importSheetCount }} sheet(s) done · {{ number_format($importSummary['added'] ?? 0) }} added, {{ number_format($importSummary['updated'] ?? 0) }} updated.
                        Keep this page open until it finishes.
                    </p>
                </div>
            </x-filament::section>
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
