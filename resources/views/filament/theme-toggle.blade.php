<button
    type="button"
    role="switch"
    class="colisap-theme-toggle"
    x-data
    x-bind:aria-checked="($store.theme === 'dark').toString()"
    x-bind:aria-label="$store.theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
    x-bind:title="$store.theme === 'dark' ? 'Dark mode on' : 'Light mode on'"
    x-on:click="$dispatch('theme-changed', $store.theme === 'dark' ? 'light' : 'dark')"
>
    <span class="colisap-theme-toggle__track" aria-hidden="true">
        <x-filament::icon icon="heroicon-m-sun" class="colisap-theme-toggle__glyph colisap-theme-toggle__glyph--sun" />
        <x-filament::icon icon="heroicon-m-moon" class="colisap-theme-toggle__glyph colisap-theme-toggle__glyph--moon" />
        <span class="colisap-theme-toggle__thumb">
            <x-filament::icon icon="heroicon-m-sun" class="colisap-theme-toggle__thumb-icon colisap-theme-toggle__thumb-icon--sun" />
            <x-filament::icon icon="heroicon-m-moon" class="colisap-theme-toggle__thumb-icon colisap-theme-toggle__thumb-icon--moon" />
        </span>
    </span>
</button>
