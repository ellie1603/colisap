<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApplicationResource\Pages\ListApplications;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class CreateModalsTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    public function test_a_new_staff_account_is_created_from_a_modal_with_its_role_logged(): void
    {
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(ListUsers::class)
            ->callAction('create', data: [
                'name' => 'Sibalom Branch',
                'email' => 'sibalom@bmpc.coop',
                'password' => 'secret-pass-1',
                'roles' => [Role::findByName(Permissions::CRS, 'web')->id],
                'is_active' => true,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Staff account created')
            ->assertNoRedirect();

        $user = User::where('email', 'sibalom@bmpc.coop')->sole();

        $this->assertTrue($user->hasRole(Permissions::CRS));
        $this->assertSame(
            ['roles' => [Permissions::CRS]],
            AuditLog::where('action', 'roles_changed')->where('auditable_id', $user->id)->sole()->new_values,
        );
    }

    public function test_a_new_role_is_created_from_a_modal_with_the_ticked_permissions(): void
    {
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(ListRoles::class)
            ->callAction('create', data: [
                'name' => 'teller',
                'permission_groups' => ['Members' => ['members.view'], 'Reports' => ['reports.view']],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Role created');

        $this->assertSame(
            ['members.view', 'reports.view'],
            Role::findByName('teller', 'web')->permissions()->orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_the_dashboard_shortcut_opens_the_new_application_form(): void
    {
        $this->actingAsRole(Permissions::CRS);

        $this->get('/admin')->assertOk()
            ->assertSee('/admin/applications?action=create', escape: false)
            ->assertSee('New application')
            ->assertDontSee('Add member');

        Livewire::withQueryParams(['action' => 'create'])
            ->test(ListApplications::class)
            ->assertSet('defaultAction', 'create')
            ->assertSeeHtml('wire:init="mountAction(');
    }

    public function test_the_old_create_page_addresses_are_gone(): void
    {
        $this->actingAsRole(Permissions::ADMIN);

        foreach (['members', 'applications', 'users', 'branches', 'roles'] as $module) {
            $this->get("/admin/{$module}/create")->assertNotFound();
        }
    }
}
