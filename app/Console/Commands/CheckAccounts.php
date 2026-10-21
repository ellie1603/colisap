<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Signature('colisap:check-accounts')]
#[Description('Security check for the live server: accounts still using a default or common password, test/demo accounts, shared generic accounts and accounts unused for 90 days. Passwords are never shown.')]
class CheckAccounts extends Command
{
    /**
     * Seeder, demo and very common passwords. A staff account must never use one of these.
     *
     * @var list<string>
     */
    private const DEFAULT_PASSWORDS = [
        'password', 'Password', 'password1', 'password123', 'Password123', 'Password1', '12345678', '123456789', '1234567890',
        'admin', 'admin123', 'Admin123', 'administrator', 'colisap', 'colisap123', 'Colisap123', 'barbaza', 'barbaza123',
        'bmpc', 'bmpc123', 'bmpc1234', 'changeme', 'change-me', 'secret', 'qwerty123', 'welcome1', 'Welcome1',
        'demo-pass-1', 'Demo-pass-2026', 'secret-pass-1', 'correct-password',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $lastSignIns = AuditLog::query()->where('action', 'login')->selectRaw('user_id, max(created_at) as last_at')->groupBy('user_id')->pluck('last_at', 'user_id');
        $problems = [];

        foreach (User::query()->with('roles')->orderBy('id')->get() as $user) {
            $label = "{$user->email} ({$user->roles->pluck('name')->implode(', ')})";

            foreach (self::DEFAULT_PASSWORDS as $candidate) {
                if (Hash::check($candidate, $user->password)) {
                    $problems[] = [$label, 'Uses a default or very common password — change it now'];

                    break;
                }
            }

            if (Str::contains(Str::lower($user->email), ['demo', 'test', 'example.'])) {
                $problems[] = [$label, 'Looks like a test or demo account — delete it if no one uses it'];
            }

            if (in_array(Str::lower($user->email), ['admin@bmpc.coop', 'crs@bmpc.coop'], true)) {
                $problems[] = [$label, 'Generic starter account — give each person their own account and deactivate this one'];
            }

            $lastUsed = $lastSignIns[$user->id] ?? null;

            if ($user->is_active && ($lastUsed === null ? $user->created_at?->lt(now()->subDays(90)) : now()->subDays(90)->gt($lastUsed))) {
                $problems[] = [$label, 'Active but not used for 90+ days — deactivate it if the person no longer needs access'];
            }
        }

        if ($problems === []) {
            $this->info('All '.User::count().' accounts passed: no default passwords, test accounts or long-unused active accounts.');

            return self::SUCCESS;
        }

        $this->table(['Account', 'Problem'], $problems);
        $this->warn(count($problems).' problem(s) found. Fix them in Administration → Staff Accounts.');

        return self::FAILURE;
    }
}
