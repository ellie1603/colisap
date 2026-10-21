<?php

namespace Tests\Feature;

use App\Exceptions\ApplicationAlreadyDecided;
use App\Filament\Resources\ApplicationResource\Pages\EditApplication;
use App\Filament\Resources\MemberResource\Pages\EditMember;
use App\Models\Application;
use App\Models\ImportBatch;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Services\Access\Permissions;
use App\Services\Masterlist\MasterlistImportService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class ConcurrentChangesTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    public function test_an_application_decided_by_someone_else_cannot_be_decided_again(): void
    {
        $application = Application::factory()->create();
        $memberStatus = $application->member->status;
        $staleCopy = Application::find($application->id);
        $application->reject('Incomplete papers');

        $this->expectException(ApplicationAlreadyDecided::class);

        try {
            $staleCopy->approve(now());
        } finally {
            $this->assertSame('rejected', $application->fresh()->status);
            $this->assertSame($memberStatus, $application->member->fresh()->status, 'The member was not reinstated.');
        }
    }

    public function test_the_second_administrator_can_no_longer_approve_an_application_rejected_meanwhile(): void
    {
        $application = Application::factory()->create();
        $memberStatus = $application->member->status;
        $this->actingAsRole(Permissions::ADMIN);

        $secondAdministrator = Livewire::test(EditApplication::class, ['record' => $application->getRouteKey()]);

        Livewire::test(EditApplication::class, ['record' => $application->getRouteKey()])
            ->callAction('reject', ['reason' => 'Incomplete papers']);

        $secondAdministrator->call('$refresh')->assertActionHidden('approve');

        $this->assertSame('rejected', $application->fresh()->status);
        $this->assertSame($memberStatus, $application->member->fresh()->status);
    }

    public function test_saving_over_someone_elses_newer_change_is_refused(): void
    {
        $member = Member::factory()->create(['contact_number' => '09170000001']);
        $this->actingAsRole(Permissions::CRS);

        $page = Livewire::test(EditMember::class, ['record' => $member->getRouteKey()]);

        $this->travel(2)->seconds();
        $member->update(['contact_number' => '09170000002']);

        $page->fillForm(['contact_number' => '09170000003'])
            ->call('save')
            ->assertNotified('Changed by someone else');

        $this->assertSame('09170000002', $member->fresh()->contact_number);
    }

    public function test_saving_twice_from_the_same_form_is_not_a_conflict(): void
    {
        $application = Application::factory()->create();
        $this->actingAsRole(Permissions::ADMIN);

        $page = Livewire::test(EditApplication::class, ['record' => $application->getRouteKey()]);

        $page->fillForm(['remarks' => 'First note'])->call('save')->assertHasNoFormErrors();
        $this->travel(2)->seconds();
        $page->fillForm(['remarks' => 'Second note'])->call('save')->assertHasNoFormErrors()->assertNotNotified('Changed by someone else');

        $this->assertSame('Second note', $application->fresh()->remarks);
    }

    public function test_an_import_already_rejected_cannot_be_approved(): void
    {
        $batch = ImportBatch::create(['file_name' => 'branch.xlsx', 'as_of_date' => now(), 'status' => 'awaiting_approval']);
        $staleCopy = ImportBatch::find($batch->id);
        $service = app(MasterlistImportService::class);
        $service->reject($batch, 'Wrong month');

        $this->expectException(RuntimeException::class);

        try {
            $service->approve($staleCopy);
        } finally {
            $this->assertSame('rejected', $batch->fresh()->status);
        }
    }

    public function test_savings_transactions_add_up_from_the_latest_balance(): void
    {
        $member = Member::factory()->create(['savings_balance' => 1000]);
        $staleCopy = Member::find($member->id);

        SavingsTransaction::create(['member_id' => $member->id, 'type' => 'deposit', 'amount' => 250, 'transaction_date' => now()]);
        SavingsTransaction::create(['member_id' => $staleCopy->id, 'type' => 'deposit', 'amount' => 100, 'transaction_date' => now()]);

        $this->assertSame('1350.00', $member->fresh()->savings_balance);
    }
}
