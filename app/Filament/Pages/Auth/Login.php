<?php

namespace App\Filament\Pages\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    public const MAX_ATTEMPTS_PER_ACCOUNT = 5;

    public const MAX_ATTEMPTS_PER_IP = 10;

    public const LOCKOUT_SECONDS = 60;

    protected static string $view = 'filament.pages.auth.login';

    public ?int $lockoutSecondsRemaining = null;

    /**
     * Shown in a web browser only: the COLISAP desktop app identifies itself in its user agent.
     */
    public bool $offersDesktopApp = false;

    public const DESKTOP_APP_FILE = 'downloads/COLISAP.exe';

    public function mount(): void
    {
        parent::mount();

        $this->offersDesktopApp = ! str_contains((string) request()->userAgent(), 'COLISAP-Desktop')
            && is_file(public_path(self::DESKTOP_APP_FILE));
    }

    /**
     * @return array{url: string, version: ?string, size: string}
     */
    public function desktopApp(): array
    {
        $versionFile = public_path('downloads/COLISAP.version');

        return [
            'url' => asset(self::DESKTOP_APP_FILE),
            'version' => is_file($versionFile) ? preg_replace('/(\.0)+$/', '', trim((string) file_get_contents($versionFile))) : null,
            'size' => number_format(filesize(public_path(self::DESKTOP_APP_FILE)) / 1048576, 1).' MB',
        ];
    }

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
            $this->recordFailedAttempt($accountKey, $ipKey, (string) ($data['email'] ?? ''), 'wrong email or password');
        }

        $user = Filament::auth()->user();

        if (($user instanceof FilamentUser) && (! $user->canAccessPanel(Filament::getCurrentPanel()))) {
            Filament::auth()->logout();

            $this->recordFailedAttempt($accountKey, $ipKey, (string) ($data['email'] ?? ''), 'account deactivated');
        }

        RateLimiter::clear($accountKey);

        session()->regenerate();

        return app(LoginResponse::class);
    }

    protected function recordFailedAttempt(string $accountKey, string $ipKey, string $email, string $reason): never
    {
        RateLimiter::hit($accountKey, self::LOCKOUT_SECONDS);
        RateLimiter::hit($ipKey, self::LOCKOUT_SECONDS);

        $remaining = RateLimiter::remaining($accountKey, self::MAX_ATTEMPTS_PER_ACCOUNT);
        $this->logFailedSignIn($email, $reason, $remaining === 0);

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

    /**
     * Every failed sign-in is kept in the audit log (the email typed, never the password); a lockout is also
     * written to the server log as a warning so repeated attacks show up in Dokploy's logs.
     */
    private function logFailedSignIn(string $email, string $reason, bool $lockedOut): void
    {
        $email = Str::limit(Str::lower(trim($email)), 191, '');
        $user = User::query()->where('email', $email)->first();

        AuditLog::create([
            'user_id' => null,
            'action' => $lockedOut ? 'login_locked' : 'login_failed',
            'module' => 'Sign-in',
            'auditable_type' => User::class,
            'auditable_id' => $user?->id ?? 0,
            'new_values' => ['email' => $email, 'reason' => $reason],
            'description' => $lockedOut ? 'Sign-in locked after repeated failed attempts' : 'Failed sign-in',
            'ip_address' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 250, ''),
        ]);

        if ($lockedOut) {
            Log::warning('Sign-in locked after repeated failed attempts', ['email' => $email, 'ip' => request()->ip()]);
        }
    }
}
