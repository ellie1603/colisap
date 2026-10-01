<?php

namespace Tests\Feature\Models\Concerns;

use App\Models\Beneficiary;
use App\Models\Claim;
use App\Models\Member;
use App\Models\SavingsTransaction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BelongsToActiveMemberTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_archiving_member_hides_their_records_from_every_module(): void
    {
        $archived = Member::factory()->create();
        $kept = Member::factory()->create();

        foreach ([$archived, $kept] as $member) {
            SavingsTransaction::factory()->for($member)->create(['amount' => 100]);
            Claim::factory()->for($member)->create();
        }

        $archived->delete();

        $this->assertSame([$kept->id], SavingsTransaction::pluck('member_id')->all());
        $this->assertSame([$kept->id], Beneficiary::pluck('member_id')->all());
        $this->assertSame([$kept->id], Claim::pluck('member_id')->all());
    }

    public function test_restoring_member_brings_their_records_back(): void
    {
        $member = Member::factory()->create();
        SavingsTransaction::factory()->for($member)->create();
        Claim::factory()->for($member)->create();

        $member->delete();
        $member->restore();

        $this->assertSame([1, 1, 1], [SavingsTransaction::count(), Beneficiary::count(), Claim::count()]);
    }
}
