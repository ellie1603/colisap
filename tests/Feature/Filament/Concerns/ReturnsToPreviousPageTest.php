<?php

namespace Tests\Feature\Filament\Concerns;

use App\Filament\Pages\Monitoring;
use App\Filament\Resources\ApplicationResource\Pages\EditApplication;
use App\Filament\Resources\BranchResource;
use App\Filament\Resources\BranchResource\Pages\EditBranch;
use App\Filament\Resources\BranchResource\Pages\ListBranches;
use App\Filament\Resources\MemberResource;
use App\Filament\Resources\MemberResource\Pages\EditMember;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Application;
use App\Models\Branch;
use App\Models\Member;
use App\Models\User;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class ReturnsToPreviousPageTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    public function test_saving_a_staff_account_returns_to_the_list_it_was_opened_from_with_the_saved_message(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $user = User::factory()->create(['is_active' => true])->assignRole(Permissions::CRS);
        $list = UserResource::getUrl('index').'?page=2';
        session()->setPreviousUrl($list);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['name' => 'Renamed Officer'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Saved')
            ->assertRedirect($list);

        $this->assertSame('Renamed Officer', $user->refresh()->name);
    }

    public function test_saving_a_member_returns_to_monitoring_when_the_member_was_opened_from_there(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $member = Member::factory()->create();
        $monitoring = Monitoring::getUrl(['tab' => 'upgrades']);
        session()->setPreviousUrl($monitoring);

        Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect($monitoring);
    }

    public function test_a_member_form_opened_directly_returns_to_the_member_profile(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $member = Member::factory()->create();

        Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
            ->call('save')
            ->assertRedirect(MemberResource::getUrl('view', ['record' => $member]));
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function previousPagesThatAreNotUsed(): array
    {
        return [
            'opened directly' => [null],
            'another website' => ['https://example.com/admin/branches'],
            'the sign-in page' => ['/admin/login'],
            'outside the panel' => ['/'],
        ];
    }

    #[DataProvider('previousPagesThatAreNotUsed')]
    public function test_a_form_without_a_usable_previous_page_returns_to_its_list(?string $previous): void
    {
        $this->actingAsRole(Permissions::ADMIN);

        if ($previous !== null) {
            session()->setPreviousUrl(str_starts_with($previous, '/') ? url($previous) : $previous);
        }

        Livewire::test(EditBranch::class, ['record' => Branch::where('name', 'Barbaza')->value('id')])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(BranchResource::getUrl('index'));
    }

    public function test_adding_a_branch_happens_in_a_modal_on_the_branch_list(): void
    {
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(ListBranches::class)
            ->callAction('create', data: ['name' => 'Pandan', 'code' => 'PAN'])
            ->assertHasNoActionErrors()
            ->assertNotified('Branch created')
            ->assertNoRedirect();

        $this->assertDatabaseHas('branches', ['name' => 'Pandan']);
    }

    public function test_a_pending_application_stays_open_after_saving_so_it_can_be_approved(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $application = Application::factory()->create(['status' => 'pending']);
        session()->setPreviousUrl(MemberResource::getUrl('index'));

        Livewire::test(EditApplication::class, ['record' => $application->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNoRedirect();
    }
}
