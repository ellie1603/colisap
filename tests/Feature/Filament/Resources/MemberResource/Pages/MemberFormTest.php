<?php

namespace Tests\Feature\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\MemberResource\Pages\CreateMember;
use App\Filament\Resources\MemberResource\Pages\EditMember;
use App\Models\Branch;
use App\Models\Member;
use App\Services\Access\Permissions;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class MemberFormTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Repeater::fake();
    }

    /**
     * @return array<string, mixed>
     */
    private function memberData(array $beneficiaries): array
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
            'activeBeneficiaries' => $beneficiaries,
        ];
    }

    public function test_crs_enrolls_member_with_beneficiaries_and_opening_balance(): void
    {
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(CreateMember::class)
            ->fillForm($this->memberData([
                ['full_name' => 'Maria Dela Cruz', 'relationship' => 'Spouse', 'share_percentage' => 60],
                ['full_name' => 'Pedro Dela Cruz', 'relationship' => 'Child', 'share_percentage' => 40],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $member = Member::where('account_no', '00101959720')->sole();

        $this->assertSame(['waiting', 'DELA CRUZ, JUAN', '750.00', 2], [$member->status, $member->account_name, $member->savings_balance, $member->activeBeneficiaries()->count()]);
        $this->assertSame('approved', $member->applications()->sole()->status);
    }

    public function test_beneficiary_shares_must_total_exactly_one_hundred_percent(): void
    {
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(CreateMember::class)
            ->fillForm($this->memberData([
                ['full_name' => 'Maria', 'relationship' => 'Spouse', 'share_percentage' => 60],
                ['full_name' => 'Pedro', 'relationship' => 'Child', 'share_percentage' => 30],
            ]))
            ->call('create')
            ->assertHasFormErrors(['activeBeneficiaries']);

        $this->assertDatabaseCount('members', 0);
    }

    public function test_at_least_one_and_at_most_three_beneficiaries(): void
    {
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(CreateMember::class)
            ->fillForm($this->memberData([]))
            ->call('create')
            ->assertHasFormErrors(['activeBeneficiaries']);

        Livewire::test(CreateMember::class)
            ->fillForm($this->memberData(array_fill(0, 4, ['full_name' => 'Child', 'relationship' => 'Child', 'share_percentage' => 25])))
            ->call('create')
            ->assertHasFormErrors(['activeBeneficiaries']);
    }

    public function test_duplicate_account_number_is_rejected(): void
    {
        Member::factory()->create(['account_no' => '00101959720']);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(CreateMember::class)
            ->fillForm($this->memberData([['full_name' => 'Maria', 'relationship' => 'Spouse', 'share_percentage' => 100]]))
            ->call('create')
            ->assertHasFormErrors(['account_no' => 'unique']);
    }

    public function test_removing_a_beneficiary_keeps_it_in_history(): void
    {
        $member = Member::factory()->create();
        $kept = $member->beneficiaries()->create(['full_name' => 'Kept', 'relationship' => 'Child', 'share_percentage' => 50, 'is_active' => true]);
        $removed = $member->beneficiaries()->create(['full_name' => 'Removed', 'relationship' => 'Child', 'share_percentage' => 50, 'is_active' => true]);
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
            ->fillForm(['activeBeneficiaries' => [['full_name' => 'Kept', 'relationship' => 'Child', 'share_percentage' => 100]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSoftDeleted($removed);
        $this->assertSame(['Kept' => '100.00'], $member->activeBeneficiaries()->pluck('share_percentage', 'full_name')->all());
        $this->assertTrue($member->beneficiaries()->onlyTrashed()->where('full_name', 'Removed')->exists(), 'Removed designations stay in history.');
    }
}
