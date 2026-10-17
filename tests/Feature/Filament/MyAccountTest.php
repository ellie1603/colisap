<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class MyAccountTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    public function test_crs_officer_opens_my_account_from_the_sidebar(): void
    {
        $this->actingAsRole(Permissions::CRS);

        $this->get(EditProfile::getUrl())
            ->assertOk()
            ->assertSee('My Account');
    }

    public function test_crs_officer_changes_their_name_without_the_current_password(): void
    {
        $officer = $this->actingAsRole(Permissions::CRS);

        Livewire::test(EditProfile::class)
            ->fillForm(['name' => 'Maria Santos'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Maria Santos', $officer->fresh()->name);
    }

    public function test_crs_officer_changes_their_email_and_password_with_the_current_password(): void
    {
        $officer = $this->actingAsRole(Permissions::CRS);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'email' => 'maria@barbazampc.test',
                'password' => 'newpass123',
                'passwordConfirmation' => 'newpass123',
                'currentPassword' => 'password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $officer->refresh();
        $this->assertSame('maria@barbazampc.test', $officer->email);
        $this->assertTrue(Hash::check('newpass123', $officer->password));
        $this->assertTrue($officer->hasRole(Permissions::CRS), 'Their role is untouched.');
    }

    public function test_password_change_is_refused_without_the_right_current_password(): void
    {
        $officer = $this->actingAsRole(Permissions::CRS);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'newpass123',
                'passwordConfirmation' => 'newpass123',
                'currentPassword' => 'wrong-password',
            ])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        $this->assertTrue(Hash::check('password', $officer->fresh()->password));
    }
}
