<?php

namespace Tests\Feature;

use App\Filament\Resources\MemberResource\Pages\ViewMember;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class MemberPersonalDataTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    public function test_the_administrator_downloads_everything_held_about_a_member(): void
    {
        $member = Member::factory()->create(['account_name' => 'SANTOS, MARIA', 'account_no' => '00101555001']);
        SavingsTransaction::create(['member_id' => $member->id, 'type' => 'deposit', 'amount' => 100, 'transaction_date' => now()]);
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
            ->callAction('exportPersonalData')
            ->assertFileDownloaded('personal-data-00101555001.json');

        $this->assertTrue(AuditLog::where('action', 'personal_data_exported')->where('auditable_id', $member->id)->exists());
    }

    public function test_crs_officers_do_not_see_the_privacy_actions(): void
    {
        $member = Member::factory()->create();
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
            ->assertActionHidden('exportPersonalData')
            ->assertActionHidden('erase');
    }

    public function test_only_an_archived_member_can_be_erased_and_the_account_number_must_be_typed(): void
    {
        $member = Member::factory()->create(['account_no' => '00101555002']);
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])->assertActionHidden('erase');

        $member->delete();

        Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
            ->callAction('erase', ['reason' => 'Member request', 'confirm_account_no' => '00101555999'])
            ->assertHasActionErrors(['confirm_account_no']);

        $this->assertNotNull(Member::withTrashed()->find($member->id));
    }

    public function test_erasing_removes_the_member_and_every_copy_of_their_details(): void
    {
        $member = Member::factory()->create(['account_name' => 'ERASEME, JUAN', 'account_no' => '00101555003']);
        Application::factory()->create(['member_id' => $member->id]);
        Application::factory()->applicant()->create(['account_no' => '00101555003', 'last_name' => 'ERASEME', 'status' => 'rejected']);
        SavingsTransaction::create(['member_id' => $member->id, 'type' => 'deposit', 'amount' => 100, 'transaction_date' => now()]);
        $member->update(['contact_number' => '09175550003']);
        $member->delete();
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
            ->callAction('erase', ['reason' => 'Data Privacy request #12', 'confirm_account_no' => '00101555003'])
            ->assertHasNoActionErrors()
            ->assertNotified('Member erased permanently');

        $this->assertNull(Member::withTrashed()->find($member->id));
        $this->assertSame(0, Application::withoutGlobalScopes()->withTrashed()->where('account_no', '00101555003')->orWhere('member_id', $member->id)->count());
        $this->assertSame(0, SavingsTransaction::where('member_id', $member->id)->count());
        $this->assertStringNotContainsString('ERASEME', json_encode(AuditLog::query()->toBase()->get()));
        $this->assertSame('Data Privacy request #12', AuditLog::where('action', 'member_erased')->sole()->reason);
    }
}
