<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use Filament\Actions\MountableAction;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\Facades\FilamentIcon;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('COLISAP — Barbaza MPC')
            ->brandLogo(fn (): View => view('filament.brand'))
            ->brandLogoHeight('2.75rem')
            // Site-relative: these are built before the request is known to be HTTPS (Dokploy's proxy),
            // and a full http:// address would be blocked by the browser on the https:// site.
            ->favicon('/images/favicon.png')
            ->font('Public Sans', url: '/fonts/public-sans/public-sans.css', provider: LocalFontProvider::class)
            ->login(Login::class)
            ->profile(EditProfile::class, isSimple: false)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->colors([
                'primary' => Color::hex('#1E88C8'),
                'warning' => Color::Orange,
                'danger' => Color::Rose,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'gray' => [
                    50 => '248, 249, 252',
                    100 => '241, 243, 249',
                    200 => '226, 230, 241',
                    300 => '203, 209, 226',
                    400 => '148, 157, 186',
                    500 => '102, 112, 145',
                    600 => '74, 84, 118',
                    700 => '52, 61, 96',
                    800 => '32, 39, 74',
                    900 => '20, 26, 58',
                    950 => '11, 15, 40',
                ],
            ])
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn () => new HtmlString('<link rel="stylesheet" href="'.e(asset('css/colisap.css')).'?v=34">'))
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn (): View => view('filament.topbar-date'))
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn (): View => view('filament.theme-toggle'))
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn (): View => view('filament.sidebar-footer'))
            ->renderHook(TablesRenderHook::TOOLBAR_TOGGLE_COLUMN_TRIGGER_BEFORE, fn (): View => view('filament.table-reset-filters'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString('<script src="'.e(asset('js/colisap.js')).'?v=10"></script>'))
            ->spa()
            ->unsavedChangesAlerts()
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(MaxWidth::Full)
            ->navigationGroups([
                NavigationGroup::make('Members')->collapsible(false),
                NavigationGroup::make('Monitoring')->collapsible(false),
                NavigationGroup::make('Data & Reports')->collapsible(false),
                NavigationGroup::make('Administration')->collapsible(false),
                NavigationGroup::make('Account')->collapsible(false),
            ])
            ->navigationItems([
                NavigationItem::make('My Account')
                    ->icon('heroicon-o-user-circle')
                    ->group('Account')
                    ->url(fn (): string => EditProfile::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs(EditProfile::getRouteName())),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    public function boot(): void
    {
        // Every pop-up form keeps its title and its buttons in view while the fields scroll between them.
        MountableAction::configureUsing(fn (MountableAction $action) => $action->stickyModalHeader()->stickyModalFooter()->modalFooterActionsAlignment(Alignment::End));

        // Every table's filters sit in one bar after its search box, as dropdowns reading "All branches",
        // "All categories"… (colisap.css lays the bar out), instead of behind a filter button.
        Table::configureUsing(fn (Table $table) => $table
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(['default' => 1, 'sm' => 2, 'lg' => 4]));

        // Important, so it runs after the filter's own set-up, which would otherwise put back Filament's plain "All".
        SelectFilter::configureUsing(function (SelectFilter $filter): void {
            if (! $filter instanceof TernaryFilter) {
                $filter->placeholder(fn () => 'All '.Str::lower(Str::plural((string) $filter->getLabel())));
            }
        }, isImportant: true);

        FilamentIcon::register([
            'panels::sidebar.collapse-button' => 'heroicon-o-bars-3-bottom-left',
            'panels::sidebar.collapse-button.rtl' => 'heroicon-o-bars-3-bottom-right',
            'panels::sidebar.expand-button' => 'heroicon-o-bars-3',
            'panels::sidebar.expand-button.rtl' => 'heroicon-o-bars-3',
            'panels::topbar.open-sidebar-button' => 'heroicon-o-bars-3',
        ]);
    }
}
