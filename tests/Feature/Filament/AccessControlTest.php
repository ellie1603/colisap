<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Monitoring;
use App\Filament\Pages\PolicySettings;
use App\Filament\Pages\Reports;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\BranchResource;
use App\Filament\Resources\ImportBatchResource;
use App\Filament\Resources\MemberResource;
use App\Filament\Resources\MemberResource\Pages\EditMember;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Member;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function accessMatrix(): array
    {
        $pages = [
            'members' => MemberResource::class.'@index',
            'import' => MemberResource::class.'@import',
            'import history' => ImportBatchResource::class.'@index',
            'monitoring' => Monitoring::class,
            'reports' => Reports::class,
            'users' => UserResource::class.'@index',
            'roles' => RoleResource::class.'@index',
            'branches' => BranchResource::class.'@index',
            'policy' => PolicySettings::class,
            'audit' => AuditLogResource::class.'@index',
        ];

        $expected = [
            Permissions::ADMIN => ['members' => 200, 'import' => 200, 'import history' => 200, 'monitoring' => 200, 'reports' => 200, 'users' => 200, 'roles' => 200, 'branches' => 200, 'policy' => 200, 'audit' => 200],
            Permissions::CRS => ['members' => 200, 'import' => 200, 'import history' => 200, 'monitoring' => 200, 'reports' => 200, 'users' => 403, 'roles' => 403, 'branches' => 403, 'policy' => 403, 'audit' => 403],
        ];

        $cases = [];

        foreach ($expected as $role => $statuses) {
            foreach ($statuses as $page => $status) {
                $cases["{$role} → {$page}"] = [$role, $pages[$page], $status];
            }
        }

        return $cases;
    }

    #[DataProvider('accessMatrix')]
    public function test_role_based_page_access(string $role, string $page, int $expectedStatus): void
    {
        $this->actingAsRole($role);
        [$class, $name] = str_contains($page, '@') ? explode('@', $page) : [$page, null];

        $this->get($name ? $class::getUrl($name) : $class::getUrl())->assertStatus($expectedStatus);
    }

    public function test_crs_cannot_change_status_category_or_segmentation(): void
    {
        $member = Member::factory()->create(['category' => '40000', 'segment' => 'R']);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
            ->assertFormFieldIsDisabled('category')
            ->assertFormFieldIsDisabled('segment')
            ->assertFormFieldIsDisabled('status')
            ->set('data.category', '60000')
            ->call('save');

        $this->assertSame('40000', $member->fresh()->category);
    }

    public function test_crs_cannot_archive_members_but_admin_can(): void
    {
        $member = Member::factory()->create();

        $this->actingAsRole(Permissions::CRS);
        Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])->assertActionHidden('delete');

        $this->actingAsRole(Permissions::ADMIN);
        Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])->callAction('delete');
        $this->assertSoftDeleted($member);
    }

    public function test_the_retired_mortuary_pages_no_longer_exist(): void
    {
        $this->actingAsRole(Permissions::ADMIN);

        $this->get('/admin/claims')->assertNotFound();
        $this->get('/admin/contributions')->assertNotFound();
    }

    public function test_deactivated_user_is_blocked_and_logged_out(): void
    {
        $member = Member::factory()->create(['approval_date' => now()->subYear()]);
        $this->actingAsRole(Permissions::ADMIN, isActive: false);

        $this->get(route('members.certificate', $member))->assertForbidden();
        $this->assertGuest();
    }

    public function test_administrator_cannot_deactivate_or_demote_own_account(): void
    {
        $user = $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertFormFieldIsDisabled('is_active')
            ->assertFormFieldIsDisabled('roles')
            ->assertActionHidden('delete')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($user->fresh()->is_active);
        $this->assertTrue($user->fresh()->hasRole(Permissions::ADMIN));
    }
}
