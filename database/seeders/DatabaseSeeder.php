<?php

namespace Database\Seeders;

use App\Models\EligibilityRule;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        collect(['admin', 'staff', 'auditor'])->each(
            fn (string $role) => Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@bmpc.coop'],
            [
                'name' => 'BMPC Administrator',
                'password' => 'password',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        $admin->assignRole('admin');

        $staff = User::firstOrCreate(
            ['email' => 'staff@bmpc.coop'],
            [
                'name' => 'COLISAP Encoder',
                'password' => 'password',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        $staff->assignRole('staff');

        $auditor = User::firstOrCreate(
            ['email' => 'auditor@bmpc.coop'],
            [
                'name' => 'Board Auditor',
                'password' => 'password',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        $auditor->assignRole('auditor');

        EligibilityRule::firstOrCreate(
            ['name' => 'Standard COLISAP Eligibility'],
            [
                'description' => 'Default eligibility rule for the COLISAP death benefit. Adjust to match the official BMPC COLISAP policy.',
                'min_membership_months' => 6,
                'min_contribution_balance' => 500,
                'max_missed_contributions' => 2,
                'benefit_amount' => 10000,
                'is_active' => true,
            ]
        );
    }
}
