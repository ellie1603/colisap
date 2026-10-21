<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    public function test_a_missing_page_shows_the_branded_not_found_page(): void
    {
        $this->actingAsRole(Permissions::ADMIN);

        $this->get('/admin/does-not-exist')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Go to the dashboard');
    }

    public function test_a_page_outside_the_role_shows_the_branded_access_page(): void
    {
        $this->actingAsRole(Permissions::CRS);

        $this->get(Reports::getUrl())
            ->assertForbidden()
            ->assertSee('You don’t have access to this page');
    }

    public function test_an_unexpected_error_shows_a_friendly_page_without_details(): void
    {
        config(['app.debug' => false]);
        Route::get('/__boom', fn () => throw new \RuntimeException('SQLSTATE secret details'));

        $this->get('/__boom')
            ->assertStatus(500)
            ->assertSee('Something went wrong')
            ->assertDontSee('SQLSTATE secret details');
    }
}
