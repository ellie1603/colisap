@if (filament()->auth()->check())
    <div class="colisap-sidebar-logout" x-bind:class="{ 'is-compact': ! $store.sidebar.isOpen }">
        <form method="POST" action="{{ filament()->getLogoutUrl() }}">
            @csrf
            <button type="submit" class="colisap-sidebar-logout__button" title="Logout">
                <x-filament::icon icon="heroicon-o-arrow-right-start-on-rectangle" class="colisap-sidebar-logout__icon" />
                <span class="colisap-sidebar-logout__label">Logout</span>
            </button>
        </form>
    </div>
@endif
