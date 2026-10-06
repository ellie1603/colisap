<?php

namespace Tests\Feature\Database\Seeders;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_production_seed_never_uses_the_default_password(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $admin = User::where('email', 'admin@bmpc.coop')->sole();
        $this->assertFalse(Hash::check('password', $admin->password));
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue(User::where('email', 'crs@bmpc.coop')->sole()->hasRole('crs'));
        $this->assertSame(['admin', 'crs'], Role::orderBy('name')->pluck('name')->all());
    }

    public function test_reseeding_does_not_reset_existing_account_passwords(): void
    {
        $this->seed(DatabaseSeeder::class);
        User::where('email', 'admin@bmpc.coop')->update(['password' => Hash::make('changed-secret-1')]);

        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Hash::check('changed-secret-1', User::where('email', 'admin@bmpc.coop')->value('password')));
    }
}
