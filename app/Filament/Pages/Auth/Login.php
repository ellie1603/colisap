<?php

namespace App\Filament\Pages\Auth;

use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    public const MAX_ATTEMPTS_PER_ACCOUNT = 5;

    public const MAX_ATTEMPTS_PER_IP = 10;

    public const LOCKOUT_SECONDS = 60;

    public const SPLASH_SESSION_KEY = 'colisap.show_splash';

    protected static string $view = 'filament.pages.auth.login';

    public ?int $lockoutSecondsRemaining = null;

    public function getHeading(): string|Htmlable
    {
        return 'Welcome back';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Sign in to continue to the COLISAP monitoring system.';
    }

    public function authenticate(): ?LoginResponse
    {
        $this->lockoutSecondsRemaining = null;

        $data = $this->form->getState();
        $accountKey = $this->accountThrottleKey($data['email'] ?? '');
        $ipKey = $this->ipThrottleKey();

        if (RateLimiter::tooManyAttempts($accountKey, self::MAX_ATTEMPTS_PER_ACCOUNT)
            || RateLimiter::tooManyAttempts($ipKey, self::MAX_ATTEMPTS_PER_IP)) {
            $this->lockoutSecondsRemaining = max(RateLimiter::availableIn($accountKey), RateLimiter::availableIn($ipKey));

            throw ValidationException::withMessages([
                'data.email' => "Too many failed sign-in attempts. Please try again in {$this->lockoutSecondsRemaining} seconds.",
            ]);
        }

        if (! Filament::auth()->attempt($this->getCredentialsFromFormData($data), $data['remember'] ?? false)) {
            $this->recordFailedAttempt($accountKey, $ipKey);
        }

        $user = Filament::auth()->user();

        if (($user instanceof FilamentUser) && (! $user->canAccessPanel(Filament::getCurrentPanel()))) {
            Filament::auth()->logout();

            $this->recordFailedAttempt($accountKey, $ipKey);
        }

        RateLimiter::clear($accountKey);

        session()->regenerate();
        session()->flash(self::SPLASH_SESSION_KEY, true);

        return app(LoginResponse::class);
    }

    protected function recordFailedAttempt(string $accountKey, string $ipKey): never
    {
        RateLimiter::hit($accountKey, self::LOCKOUT_SECONDS);
        RateLimiter::hit($ipKey, self::LOCKOUT_SECONDS);

        $remaining = RateLimiter::remaining($accountKey, self::MAX_ATTEMPTS_PER_ACCOUNT);

        if ($remaining === 0) {
            $this->lockoutSecondsRemaining = RateLimiter::availableIn($accountKey);

            throw ValidationException::withMessages([
                'data.email' => "Too many failed sign-in attempts. Please try again in {$this->lockoutSecondsRemaining} seconds.",
            ]);
        }

        $message = __('filament-panels::pages/auth/login.messages.failed');

        if ($remaining <= 2) {
            $message .= ' '.trans_choice('{1} You have 1 attempt left before a 1-minute lockout.|[2,*] You have :count attempts left before a 1-minute lockout.', $remaining);
        }

        throw ValidationException::withMessages(['data.email' => $message]);
    }

    protected function accountThrottleKey(string $email): string
    {
        return 'colisap-login:account:'.Str::transliterate(Str::lower($email)).'|'.request()->ip();
    }

    protected function ipThrottleKey(): string
    {
        return 'colisap-login:ip:'.request()->ip();
    }
}
