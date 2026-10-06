<?php

namespace Tests\Feature\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\MemberResource;
use App\Filament\Resources\MemberResource\Pages\CreateMember;
use App\Models\Branch;
use App\Models\Member;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class MemberFormTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function memberData(): array
    {
        return [
            'account_no' => '00101959720',
            'branch_id' => Branch::where('name', 'Sibalom')->value('id'),
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan',
            'approval_date' => now()->subDays(10)->toDateString(),
            'category' => '40000',
            'segment' => 'G',
            'savings_balance' => 750,
        ];
    }

    public function test_crs_enrolls_member_with_opening_balance(): void
    {
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(CreateMember::class)
            ->fillForm($this->memberData())
            ->call('create')
            ->assertHasNoFormErrors();

        $member = Member::where('account_no', '00101959720')->sole();

        $this->assertSame(['waiting', 'DELA CRUZ, JUAN', '750.00'], [$member->status, $member->account_name, $member->savings_balance]);
        $this->assertSame('approved', $member->applications()->sole()->status);
    }

    public function test_duplicate_account_number_is_rejected(): void
    {
        Member::factory()->create(['account_no' => '00101959720']);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(CreateMember::class)
            ->fillForm($this->memberData())
            ->call('create')
            ->assertHasFormErrors(['account_no' => 'unique']);
    }

    public function test_member_screens_no_longer_ask_for_or_show_beneficiaries(): void
    {
        $member = Member::factory()->create();
        $this->actingAsRole(Permissions::ADMIN);

        $this->get(MemberResource::getUrl('create'))->assertOk()->assertDontSee('Beneficiar');
        $this->get(MemberResource::getUrl('view', ['record' => $member]))->assertOk()->assertDontSee('Beneficiar');
        $this->get('/admin/beneficiaries')->assertNotFound();
        $this->assertTrue($member->isEligible(), 'Coverage no longer depends on beneficiaries.');
    }
}
