<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">Active Members List</x-slot>
            <p class="text-sm text-gray-500 mb-4">All currently active COLISAP members.</p>
            <x-filament::button tag="a" href="{{ route('reports.active-members') }}" target="_blank" icon="heroicon-o-arrow-down-tray">
                Download PDF
            </x-filament::button>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Contribution Summary</x-slot>
            <p class="text-sm text-gray-500 mb-4">Total contributions per member for the current month.</p>
            <x-filament::button tag="a" href="{{ route('reports.contribution-summary') }}" target="_blank" icon="heroicon-o-arrow-down-tray">
                Download PDF
            </x-filament::button>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Claims Processed</x-slot>
            <p class="text-sm text-gray-500 mb-4">Claims filed so far this year, with status and amounts.</p>
            <x-filament::button tag="a" href="{{ route('reports.claims-processed') }}" target="_blank" icon="heroicon-o-arrow-down-tray">
                Download PDF
            </x-filament::button>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Eligibility Status</x-slot>
            <p class="text-sm text-gray-500 mb-4">Every active member's eligibility for the death benefit under the current rule.</p>
            <x-filament::button tag="a" href="{{ route('reports.eligibility-status') }}" target="_blank" icon="heroicon-o-arrow-down-tray">
                Download PDF
            </x-filament::button>
        </x-filament::section>
    </div>
</x-filament-panels::page>
