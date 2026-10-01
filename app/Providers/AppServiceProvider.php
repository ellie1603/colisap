<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Access\Permissions;
use App\Services\Policy\PolicySettings;
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
        // The Super Admin has every permission; everyone else is checked against role permissions.
        Gate::before(fn (User $user) => $user->hasRole(Permissions::SUPER_ADMIN) ? true : null);
    }
}
