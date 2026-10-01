<x-filament-widgets::widget>
    <x-filament::section>
        <div class="colisap-hero">
            <img src="{{ asset('images/logo-192.png') }}" alt="COLISAP logo">
            <div>
                <div class="colisap-hero__title">Barbaza Multi-Purpose Cooperative — Coop Life Savings Program</div>
                <p class="colisap-muted">
                    Welcome, {{ $user?->name }}. Figures update automatically from the member database.
                    Policy in force: {{ implode(' · ', $rules) }}.
                </p>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
