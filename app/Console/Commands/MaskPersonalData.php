<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

#[Signature('colisap:mask-personal-data {--confirm= : The database name, to confirm without being asked}')]
#[Description('For a development or test COPY only: replace every member\'s personal details with fake ones, keeping statuses, categories, balances and dates.')]
class MaskPersonalData extends Command
{
    /**
     * @var array<string, string> real Acct. Number => masked Acct. Number
     */
    private array $accounts = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('Refused: this is the production system. Run it only on a copy of the data.');

            return self::FAILURE;
        }

        $database = (string) DB::connection()->getDatabaseName();
        $typed = $this->option('confirm') ?? $this->ask("This permanently replaces real names, birthdates, numbers and addresses in \"{$database}\". Type the database name to continue");

        if ($typed !== $database) {
            $this->error('Not confirmed. Nothing was changed.');

            return self::FAILURE;
        }

        DB::transaction(function () {
            $this->maskMembers();
            $this->maskApplications();
            $this->maskImportRows();
            $this->clearFreeText();
        });

        $this->removeRealFiles();

        $this->info(number_format(count($this->accounts)).' members masked. Staff accounts, statuses, categories, balances and dates were kept.');

        return self::SUCCESS;
    }

    private function maskMembers(): void
    {
        DB::table('members')->orderBy('id')->chunkById(500, function ($members) {
            foreach ($members as $member) {
                [$last, $first, $middle] = [mb_strtoupper(fake()->lastName()), fake()->firstName(), fake()->lastName()];
                $accountNo = $this->maskAccount((string) $member->account_no, '9', $member->id);

                DB::table('members')->where('id', $member->id)->update([
                    'account_no' => $accountNo,
                    'account_name' => "{$last}, ".mb_strtoupper($first).' '.mb_strtoupper(mb_substr($middle, 0, 1)).'.',
                    'last_name' => $last,
                    'first_name' => $first,
                    'middle_name' => $middle,
                    'suffix' => null,
                    'birthdate' => $this->sameYear($member->birthdate),
                    'contact_number' => $member->contact_number ? '09'.fake()->numerify('#########') : null,
                    'email' => $member->email ? fake()->unique()->safeEmail() : null,
                    'address' => $member->address ? fake()->streetAddress() : null,
                    'source_values' => null,
                    'remarks' => null,
                    'withdrawal_reason' => $member->withdrawal_reason ? 'Personal reasons' : null,
                    'withdrawal_document' => null,
                ]);
            }
        });
    }

    private function maskApplications(): void
    {
        $members = DB::table('members')->pluck('account_no', 'id');

        DB::table('applications')->orderBy('id')->chunkById(500, function ($applications) use ($members) {
            foreach ($applications as $application) {
                DB::table('applications')->where('id', $application->id)->update([
                    'account_no' => $application->member_id
                        ? ($members[$application->member_id] ?? null)
                        : ($application->account_no ? $this->maskAccount((string) $application->account_no, '8', $application->id) : null),
                    'last_name' => $application->last_name ? mb_strtoupper(fake()->lastName()) : null,
                    'first_name' => $application->first_name ? fake()->firstName() : null,
                    'middle_name' => $application->middle_name ? fake()->lastName() : null,
                    'birthdate' => $this->sameYear($application->birthdate),
                    'contact_number' => $application->contact_number ? '09'.fake()->numerify('#########') : null,
                    'remarks' => null,
                    'rejection_reason' => $application->rejection_reason ? 'Requirements incomplete' : null,
                ]);
            }
        });
    }

    private function maskImportRows(): void
    {
        DB::table('import_rows')->orderBy('id')->chunkById(1000, function ($rows) {
            foreach ($rows as $row) {
                DB::table('import_rows')->where('id', $row->id)->update([
                    'account_no' => $row->account_no ? ($this->accounts[$row->account_no] ?? $this->maskAccount((string) $row->account_no, '7', $row->id)) : null,
                    'account_name' => $row->account_name ? mb_strtoupper(fake()->lastName().', '.fake()->firstName()) : null,
                    'data' => null,
                    'raw' => null,
                ]);
            }
        });
    }

    /**
     * Free-text notes and logged before/after values can hold names and numbers, so they are emptied.
     */
    private function clearFreeText(): void
    {
        DB::table('audit_logs')->update(['old_values' => null, 'new_values' => null, 'reason' => null, 'description' => null, 'ip_address' => null, 'user_agent' => null]);
        DB::table('savings_transactions')->update(['remarks' => null, 'reference_no' => null]);

        if (Schema::hasColumn('replenishment_notices', 'remarks')) {
            DB::table('replenishment_notices')->update(['remarks' => null]);
        }

        DB::table('sessions')->delete();
    }

    /**
     * Uploaded letters and imports (which hold the real data) do not belong in a masked copy.
     */
    private function removeRealFiles(): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->allFiles() as $file) {
            if (basename($file) !== '.gitignore') {
                $disk->delete($file);
            }
        }
    }

    /**
     * Keeps the branch code (first three digits) so branch matching still works; the rest is made up but unique.
     */
    private function maskAccount(string $real, string $marker, int $id): string
    {
        $masked = substr($real, 0, 3).$marker.str_pad((string) $id, 7, '0', STR_PAD_LEFT);
        $this->accounts[$real] = $masked;

        return $masked;
    }

    /**
     * A made-up birthdate in the same year, so ages (and the entry-age rule) stay realistic.
     */
    private function sameYear(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->startOfYear()->addDays(random_int(0, 360))->toDateString() : null;
    }
}
