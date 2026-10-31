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

    {{-- View window: filled from the row's data in the browser (BmpcMemberResource::viewDetails()), so it opens instantly. --}}
    <div
        x-data="{ member: null }"
        x-on:bmpc-member-view.window="member = $event.detail; $nextTick(() => $dispatch('open-modal', { id: 'bmpc-member-view' }))"
    >
        <x-filament::modal id="bmpc-member-view" width="4xl" footer-actions-alignment="end">
            <x-slot name="heading">
                <span x-text="member?.title"></span>
            </x-slot>

            <div class="colisap-details-table">
                <table>
                    <tbody>
                        <template x-for="(pair, index) in (member?.rows ?? [])" x-bind:key="index">
                            <tr>
                                <th scope="row" x-text="pair[0][0]"></th>
                                <td
                                    x-text="pair[0][1]"
                                    x-bind:colspan="pair.length === 1 ? 3 : null"
                                    x-bind:class="{ 'is-mono': pair[0][0] === 'Acct. Number', 'is-empty': pair[0][1] === '—' }"
                                ></td>
                                <template x-if="pair.length > 1">
                                    <th scope="row" x-text="pair[1][0]"></th>
                                </template>
                                <template x-if="pair.length > 1">
                                    <td x-text="pair[1][1]" x-bind:class="{ 'is-empty': pair[1][1] === '—' }"></td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <x-slot name="footerActions">
                <x-filament::button
                    icon="heroicon-m-pencil-square"
                    x-show="member?.canEdit"
                    x-on:click="$dispatch('close-modal', { id: 'bmpc-member-view' }); $wire.mountTableAction('edit', member.key)"
                >
                    Edit
                </x-filament::button>

                <x-filament::button
                    color="gray"
                    x-on:click="$dispatch('close-modal', { id: 'bmpc-member-view' })"
                >
                    Close
                </x-filament::button>
            </x-slot>
        </x-filament::modal>
    </div>
</x-filament-panels::page>
