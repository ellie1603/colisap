<?php

namespace Tests\Feature\Services\Colisap;

use App\Models\BmpcMember;
use App\Models\Member;
use App\Services\Colisap\BmpcMemberLink;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BmpcMemberLinkTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-30 08:00:00');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function names(): array
    {
        return [
            'same name' => ['DELA CRUZ, JUAN', 'DELA CRUZ, JUAN', true],
            'middle name added' => ['ESPARES, FELIMON', 'ESPARES, FELIMON MONDIA', true],
            'initial and period' => ['SOLIS, ALICIA', 'SOLIS, ALICIA C.', true],
            'stray period and number tag' => ['NECOR, GENOVEVA(2)', 'NECOR, GENOVEVA MABILEN', true],
            'abbreviation without period' => ['DOMINGO, MA. TERESA', 'DOMINGO, MA TERESA', true],
            'different first name' => ['VISTA, WILFREDO', 'VISTA, WILLIE', false],
            'different surname' => ['GARION, ELLA COZETTE', 'MANOGURA, ELLA COZETTE', false],
            'no comma' => ['JUAN DELA CRUZ', 'DELA CRUZ, JUAN', false],
        ];
    }

    #[DataProvider('names')]
    public function test_names_match_on_surname_and_first_given_name(string $colisapName, string $bmpcName, bool $expected): void
    {
        $this->assertSame($expected, BmpcMemberLink::namesMatch($colisapName, $bmpcName));
    }

    public function test_member_takes_the_details_of_the_bmpc_member_with_the_same_savings_number_and_name(): void
    {
        $member = Member::factory()->create([
            'account_no' => '00101959720', 'account_name' => 'DELA CRUZ, JUAN', 'segment' => 'S',
            'birthdate' => '1980-01-01', 'contact_number' => '09170000000', 'savings_balance' => 5000, 'cif_key' => null,
        ]);
        BmpcMember::factory()->create([
            'savings_no' => '00101959720', 'account_name' => 'DELA CRUZ, JUAN ANDRES', 'cif_key' => '0010000006392',
            'birthdate' => '1975-05-05', 'contact_number' => '09181234567', 'segment' => 'G',
            'savings_balance' => 1500, 'last_transaction_date' => '2026-06-01',
        ]);

        $this->assertSame(1, app(BmpcMemberLink::class)->syncAll());

        $member->refresh();
        $this->assertSame(
            ['0010000006392', '1975-05-05', '09181234567', 'G', '1500.00', '2026-06-01', 'dormant-bal'],
            [$member->cif_key, $member->birthdate->toDateString(), $member->contact_number, $member->segment, $member->savings_balance, $member->last_activity_date->toDateString(), $member->savings_account_status],
        );
        $this->assertSame(BmpcMemberLink::CHANGE_REASON, $member->histories()->where('field', 'segment')->sole()->reason);
        $this->assertSame(0, app(BmpcMemberLink::class)->syncAll(), 'Nothing changes the second time.');
    }

    public function test_a_different_name_or_savings_number_is_not_matched_and_blank_bmpc_details_are_kept(): void
    {
        $otherPerson = Member::factory()->create(['account_no' => '00101000001', 'account_name' => 'VISTA, WILFREDO', 'cif_key' => null]);
        BmpcMember::factory()->create(['savings_no' => '00101000001', 'account_name' => 'VISTA, WILLIE']);

        $matched = Member::factory()->create(['account_no' => '00101000002', 'account_name' => 'SOLIS, ALICIA', 'contact_number' => '09170000000', 'savings_balance' => 5000]);
        BmpcMember::factory()->create([
            'savings_no' => '00101000002', 'account_name' => 'SOLIS, ALICIA C.', 'cif_key' => '0010000000002',
            'contact_number' => null, 'birthdate' => null, 'savings_balance' => null,
        ]);

        app(BmpcMemberLink::class)->syncAll();

        $this->assertNull($otherPerson->refresh()->cif_key);
        $this->assertSame(['0010000000002', '09170000000', '5000.00'], [$matched->refresh()->cif_key, $matched->contact_number, $matched->savings_balance]);
    }
}
