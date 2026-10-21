<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Member;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaskPersonalDataTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_personal_details_are_replaced_and_the_rest_is_kept(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('withdrawals/letter.pdf', 'REAL LETTER');
        $member = Member::factory()->create([
            'account_no' => '00101234567', 'account_name' => 'DELA CRUZ, JUAN', 'last_name' => 'DELA CRUZ', 'first_name' => 'Juan',
            'birthdate' => '1980-05-20', 'contact_number' => '09171234567', 'status' => 'active', 'category' => '60000', 'savings_balance' => 3210,
        ]);
        Application::factory()->create(['member_id' => $member->id]);
        $member->update(['contact_number' => '09179999999']);

        $this->artisan('colisap:mask-personal-data', ['--confirm' => ':memory:'])->assertSuccessful();

        $masked = $member->fresh();
        $this->assertNotSame('DELA CRUZ, JUAN', $masked->account_name);
        $this->assertNotSame('09179999999', $masked->contact_number);
        $this->assertStringStartsWith('001', $masked->account_no, 'The branch code is kept.');
        $this->assertNotSame('00101234567', $masked->account_no);
        $this->assertSame('1980', $masked->birthdate->format('Y'), 'The age stays realistic.');
        $this->assertSame(['active', '60000', '3210.00'], [$masked->status, $masked->category, $masked->savings_balance]);
        $this->assertSame($masked->account_no, Application::where('member_id', $member->id)->value('account_no'));
        $this->assertStringNotContainsString('DELA CRUZ', json_encode(AuditLog::query()->toBase()->get()));
        $this->assertFalse(Storage::disk('local')->exists('withdrawals/letter.pdf'));
    }

    public function test_it_refuses_without_the_database_name(): void
    {
        $member = Member::factory()->create(['account_name' => 'SANTOS, MARIA']);

        $this->artisan('colisap:mask-personal-data', ['--confirm' => 'colisap'])->assertFailed();

        $this->assertSame('SANTOS, MARIA', $member->fresh()->account_name);
    }

    public function test_it_never_runs_on_the_production_system(): void
    {
        $member = Member::factory()->create(['account_name' => 'SANTOS, MARIA']);
        $this->app['env'] = 'production';

        $this->artisan('colisap:mask-personal-data', ['--confirm' => ':memory:'])->assertFailed();

        $this->assertSame('SANTOS, MARIA', $member->fresh()->account_name);
    }
}
