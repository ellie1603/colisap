<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Access\Permissions;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Starter data. Branches, policy settings, roles and permissions are created by migrations;
 * this only makes sure they exist and creates the first accounts.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(Permissions::ALL) as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate(Permissions::SUPER_ADMIN, 'web');

        foreach (Permissions::defaults() as $role => $permissions) {
            $model = Role::findOrCreate($role, 'web');

            // Only fill roles that have never been configured; never overwrite a Super Admin's changes.
            if ($model->permissions()->doesntExist()) {
                $model->syncPermissions($permissions);
            }
        }

        $this->seedAccount('superadmin@bmpc.coop', 'COLISAP Super Admin', Permissions::SUPER_ADMIN);
        $this->seedAccount('admin@bmpc.coop', 'BMPC Administrator', Permissions::ADMIN);
        $this->seedAccount('crs@bmpc.coop', 'COLISAP CRS', Permissions::CRS);
    }

    /**
     * Create a starter account once. Outside local development a random password is generated
     * and printed a single time, so no live account ever uses a known default password.
     */
    private function seedAccount(string $email, string $name, string $role): void
    {
        if (User::where('email', $email)->exists()) {
            return;
        }

        $password = app()->environment('local', 'testing') ? 'password' : Str::password(16, symbols: false);

        User::create([
            'email' => $email,
            'name' => $name,
            'password' => $password,
            'is_active' => true,
        ])->assignRole($role);

        $this->command?->warn("Created {$role} account {$email} with password: {$password}");
    }
}
