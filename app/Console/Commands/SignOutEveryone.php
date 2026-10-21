<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('colisap:sign-out-everyone {--force : Do not ask for confirmation}')]
#[Description('Incident response: end every staff session at once ("remember me" included), so everyone must sign in again.')]
class SignOutEveryone extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Sign out every staff member now? They will all have to sign in again.')) {
            return self::FAILURE;
        }

        $sessions = DB::table('sessions')->delete();

        // A new remember-me token makes every saved "remember me" cookie useless.
        User::query()->each(fn (User $user) => $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly());

        AuditLog::create([
            'action' => 'signed_out_everyone',
            'module' => 'Sign-in',
            'auditable_type' => User::class,
            'auditable_id' => 0,
            'new_values' => ['sessions_ended' => $sessions],
            'description' => 'Every staff session ended (incident response)',
        ]);

        $this->info("{$sessions} session(s) ended. Every staff member must sign in again.");

        return self::SUCCESS;
    }
}
