<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    <div class="flex flex-col gap-y-6">
        @if ($savingsFile)
            @php
                $done = $savingsSheetCount - count($savingsSheets);
                $percent = $savingsSheetCount ? (int) floor($done / $savingsSheetCount * 100) : 0;
            @endphp
            <x-filament::section wire:poll.500ms="importNextSavingsSheet">
                <x-slot name="heading">Importing savings…</x-slot>
                <div class="colisap-stack" role="status" aria-live="polite">
                    <div class="colisap-progress"><div class="colisap-progress__bar" style="width: {{ $percent }}%"></div></div>
                    <p class="colisap-muted">
                        {{ $done }} of {{ $savingsSheetCount }} sheet(s) done · {{ number_format($savingsSummary['updated'] ?? 0) }} member(s) updated.
                        Keep this page open until it finishes.
                    </p>
                </div>
            </x-filament::section>
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
