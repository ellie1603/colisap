<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\Facades\FilamentIcon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
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
            ->favicon(asset('images/favicon.png'))
            ->font('Public Sans', url: asset('fonts/public-sans/public-sans.css'), provider: LocalFontProvider::class)
            ->login(Login::class)
            ->profile(isSimple: false)
            ->colors([
                'primary' => Color::hex('#2E3A8C'),
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
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn () => new HtmlString('<link rel="stylesheet" href="'.e(asset('css/colisap.css')).'?v=13">'))
            ->renderHook(PanelsRenderHook::TOPBAR_START, fn (): View => view('filament.topbar-back'))
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn (): View => view('filament.topbar-date'))
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn (): View => view('filament.theme-toggle'))
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn (): View => view('filament.sidebar-footer'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString('<script src="'.e(asset('js/colisap.js')).'?v=6"></script>'))
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string|View => session(Login::SPLASH_SESSION_KEY) && auth()->check()
                ? view('filament.splash', ['userName' => auth()->user()->name])
                : '')
            ->spa()
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(MaxWidth::Full)
            ->navigationGroups([
                NavigationGroup::make('Members')->collapsible(false),
                NavigationGroup::make('Monitoring')->collapsible(false),
                NavigationGroup::make('Data & Reports')->collapsible(false),
                NavigationGroup::make('Administration')->collapsible(false),
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
        FilamentIcon::register([
            'panels::sidebar.collapse-button' => 'heroicon-o-bars-3-bottom-left',
            'panels::sidebar.collapse-button.rtl' => 'heroicon-o-bars-3-bottom-right',
            'panels::sidebar.expand-button' => 'heroicon-o-bars-3',
            'panels::sidebar.expand-button.rtl' => 'heroicon-o-bars-3',
            'panels::topbar.open-sidebar-button' => 'heroicon-o-bars-3',
        ]);
    }
}
