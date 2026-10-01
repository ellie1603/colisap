<?php

namespace Tests\Feature\Database\Seeders;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_production_seed_never_uses_the_default_password(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $superAdmin = User::where('email', 'superadmin@bmpc.coop')->sole();
        $this->assertFalse(Hash::check('password', $superAdmin->password));
        $this->assertTrue($superAdmin->hasRole('super_admin'));
        $this->assertTrue(User::where('email', 'crs@bmpc.coop')->sole()->hasRole('crs'));
    }

    public function test_reseeding_does_not_reset_existing_account_passwords(): void
    {
        $this->seed(DatabaseSeeder::class);
        User::where('email', 'admin@bmpc.coop')->update(['password' => Hash::make('changed-secret-1')]);

        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Hash::check('changed-secret-1', User::where('email', 'admin@bmpc.coop')->value('password')));
    }
}
