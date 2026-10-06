<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeUser(bool $isActive = true): User
    {
        return User::factory()
            ->create(['email' => 'staff@example.com', 'password' => 'correct-password', 'is_active' => $isActive])
            ->assignRole(Permissions::ADMIN);
    }

    private function attemptLogin(string $password): Testable
    {
        return Livewire::test(Login::class)
            ->fillForm(['email' => 'staff@example.com', 'password' => $password])
            ->call('authenticate');
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('colisap-login:account:staff@example.com|127.0.0.1');
        RateLimiter::clear('colisap-login:ip:127.0.0.1');

        parent::tearDown();
    }

    public function test_correct_credentials_sign_in_and_show_the_splash_on_the_dashboard(): void
    {
        $this->makeUser();

        $this->attemptLogin('correct-password')->assertHasNoFormErrors()->assertRedirect();

        $this->assertAuthenticated();
        $this->assertTrue(session(Login::SPLASH_SESSION_KEY));
        $this->get('/admin')->assertOk()->assertSee('colisap-splash', escape: false);
    }

    public function test_pages_load_the_self_hosted_font_instead_of_an_external_font_server(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('fonts/public-sans/public-sans.css', escape: false)
            ->assertDontSee('fonts.bunny.net', escape: false)
            ->assertDontSee('fonts.googleapis.com', escape: false);
    }

    public function test_wrong_password_does_not_sign_in_or_queue_the_splash(): void
    {
        $this->makeUser();

        $this->attemptLogin('wrong-password')->assertHasFormErrors(['email']);

        $this->assertGuest();
        $this->assertNull(session(Login::SPLASH_SESSION_KEY));
    }

    public function test_the_splash_is_not_shown_without_a_fresh_login(): void
    {
        $this->actingAs($this->makeUser());

        $this->get('/admin')->assertOk()->assertDontSee('id="colisap-splash"', escape: false);
    }

    public function test_deactivated_users_are_rejected_and_do_not_see_the_splash(): void
    {
        $this->makeUser(isActive: false);

        $this->attemptLogin('correct-password')->assertHasFormErrors(['email']);

        $this->assertGuest();
        $this->assertNull(session(Login::SPLASH_SESSION_KEY));
    }

    public function test_the_account_is_locked_after_too_many_failed_attempts(): void
    {
        $this->freezeTime();
        $this->makeUser();

        for ($attempt = 1; $attempt < Login::MAX_ATTEMPTS_PER_ACCOUNT; $attempt++) {
            $this->attemptLogin('wrong-password')->assertSet('lockoutSecondsRemaining', null);
        }

        $this->attemptLogin('wrong-password')->assertSet('lockoutSecondsRemaining', Login::LOCKOUT_SECONDS);

        $this->attemptLogin('correct-password')->assertHasFormErrors(['email']);
        $this->assertGuest();

        $this->travel(Login::LOCKOUT_SECONDS + 1)->seconds();

        $this->attemptLogin('correct-password')->assertHasNoFormErrors();
        $this->assertAuthenticated();
    }

    public function test_a_successful_sign_in_resets_the_failed_attempt_counter(): void
    {
        $this->makeUser();

        for ($attempt = 1; $attempt < Login::MAX_ATTEMPTS_PER_ACCOUNT; $attempt++) {
            $this->attemptLogin('wrong-password');
        }

        $this->attemptLogin('correct-password')->assertHasNoFormErrors();

        $this->assertSame(
            Login::MAX_ATTEMPTS_PER_ACCOUNT,
            RateLimiter::remaining('colisap-login:account:staff@example.com|127.0.0.1', Login::MAX_ATTEMPTS_PER_ACCOUNT),
        );
    }
}
