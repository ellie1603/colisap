<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Access\Permissions;
use App\Services\Policy\PolicySettings;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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

        // Staff passwords: 8+ characters with letters and numbers, and not a password known from data breaches
        // (checked against Have I Been Pwned by the first 5 characters of its hash; skipped if unreachable).
        Password::defaults(fn () => Password::min(8)->letters()->numbers()->uncompromised());

        $this->logSlowRequests();

        // Every sign-in is logged, so the System Usage report shows which staff accounts are really used.
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->recordActivity('login', 'Sign-in');
            }
        });
    }

    /**
     * Performance monitoring in production: a page that takes over 3 seconds, or spends over 2 seconds in the
     * database, is written to the log (Dokploy → Logs) with its address and timing, never with form data.
     */
    private function logSlowRequests(): void
    {
        $kernel = $this->app->make(HttpKernel::class);

        if (method_exists($kernel, 'whenRequestLifecycleIsLongerThan')) {
            $kernel->whenRequestLifecycleIsLongerThan(3000, function (Carbon $startedAt, Request $request, $response) {
                Log::warning('Slow page', [
                    'method' => $request->method(),
                    'path' => '/'.ltrim($request->path(), '/'),
                    'status' => $response->getStatusCode(),
                    'ms' => (int) $startedAt->diffInMilliseconds(now()),
                    'user_id' => $request->user()?->id,
                ]);
            });
        }

        DB::whenQueryingForLongerThan(2000, function (Connection $connection) {
            Log::warning('Slow database work in one request', [
                'path' => app()->runningInConsole() ? 'console' : '/'.ltrim(request()->path(), '/'),
                'ms' => (int) $connection->totalQueryDuration(),
            ]);
        });
    }
}
