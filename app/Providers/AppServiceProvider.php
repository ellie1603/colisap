<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Access\Permissions;
use App\Services\Policy\PolicySettings;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PolicySettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The Administrator has every permission; everyone else is checked against role permissions.
        Gate::before(fn (User $user) => $user->hasRole(Permissions::ADMIN) ? true : null);

        // Every sign-in is logged, so the System Usage report shows which staff accounts are really used.
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->recordActivity('login', 'Sign-in');
            }
        });
    }
}
