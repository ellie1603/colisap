<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\PolicySettings;
use App\Filament\Resources\MemberResource\Pages\ListMembers;
use App\Models\Branch;
use App\Models\Member;
use App\Services\Access\Permissions;
use App\Services\Masterlist\WorkbookParser;
use App\Services\Policy\PolicySettings as PolicySettingsService;
use App\Services\Reports\ReportService;
use App\Services\Sms\MonitoringSms;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class GeneralPolicyTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    public function test_category_names_everywhere_follow_the_benefit_amounts_in_policy_settings(): void
    {
        $this->assertSame(['40000' => '40K', '60000' => '60K'], Member::categoryOptions());

        app(PolicySettingsService::class)->update(['benefit_40k' => 50000, 'benefit_60k' => 82500]);

        $this->assertSame(['40000' => '50K', '60000' => '82.5K'], Member::categoryOptions());
        $this->assertSame(['50K members', '82.5K members'], [ReportService::label('category_40000'), ReportService::label('category_60000')]);

        $member = Member::factory()->create(['first_name' => 'Ana', 'savings_balance' => 3000, 'branch_id' => Branch::where('name', 'Culasi')->value('id')]);

        $this->assertSame(
            'Good day, Ana! Your savings of P3,000 qualifies you to upgrade your COLISAP coverage from 50K to the 82.5K category. Please visit your Barbaza MPC branch (Culasi) to apply. - Barbaza MPC',
            app(MonitoringSms::class)->message('qualified', $member),
        );
    }

    public function test_the_masterlist_category_column_is_read_with_the_current_benefit_amounts(): void
    {
        $parser = app(WorkbookParser::class);
        $amounts = ['40000' => 50000.0, '60000' => 80000.0];

        $this->assertSame(
            ['40000', '60000', '60000', '40000', '60000', null],
            [
                $parser->parseCategory('COLISAP50000', $amounts),
                $parser->parseCategory('80,000', $amounts),
                $parser->parseCategory('80K', $amounts),
                $parser->parseCategory('COLISAP40000', $amounts),
                $parser->parseCategory('COLISAP60000', $amounts),
                $parser->parseCategory('COLISAP30000', $amounts),
            ],
        );
    }

    public function test_policy_settings_use_general_wording_and_no_longer_show_the_program_section(): void
    {
        $this->actingAsRole(Permissions::ADMIN);

        Livewire::test(PolicySettings::class)
            ->assertSee('Basic category benefit')
            ->assertSee('Higher category benefit')
            ->assertSee('Basic category minimum maintaining balance')
            ->assertDontSee('40K category benefit')
            ->assertDontSee('60K minimum maintaining balance')
            ->assertFormFieldExists('min_balance_60k')
            ->assertDontSee('Re-application fee')
            ->assertDontSee('Minimum program participants')
            ->set('data.min_balance_60k', 2500)
            ->call('save')
            ->assertNotified('Policy settings saved');

        $policy = app(PolicySettingsService::class);

        $this->assertSame([2500.0, 30.0, 30], [$policy->decimal('min_balance_60k'), $policy->decimal('reapplication_fee'), $policy->int('alert_window_days')]);
    }

    public function test_regular_is_no_longer_a_segment_and_imports_as_unassigned_without_a_complaint(): void
    {
        $this->assertSame(['D' => 'Diamond', 'G' => 'Gold', 'S' => 'Silver'], Member::SEGMENTS);
        $this->assertArrayNotHasKey('segment_R', ReportService::REPORTS);

        $parser = app(WorkbookParser::class);
        [, $columns] = $parser->detectHeader([['Acct. Number', 'Account Name', 'Segmentation', 'Category', 'NEW Date of Approval']]);
        $row = $parser->normalizeRow(['00101959720', 'DELA CRUZ, JUAN A.', 'R', 'COLISAP40000', '6/3/1998'], $columns);

        $this->assertNull($row['data']['segment']);
        $this->assertSame(['No segmentation — left Unassigned'], $row['warnings']);
    }

    public function test_tables_offer_reset_filters_instead_of_the_column_toggle(): void
    {
        $barbaza = Member::factory()->create(['branch_id' => Branch::where('name', 'Barbaza')->value('id')]);
        $kalibo = Member::factory()->create(['branch_id' => Branch::where('name', 'Kalibo')->value('id')]);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(ListMembers::class)
            ->assertSeeHtml('wire:click="resetTableFiltersForm"')
            ->assertDontSeeHtml('fi-ta-col-toggle')
            ->filterTable('branch_id', [$barbaza->branch_id])
            ->assertCanNotSeeTableRecords([$kalibo])
            ->call('resetTableFiltersForm')
            ->assertCanSeeTableRecords([$barbaza, $kalibo]);
    }
}
