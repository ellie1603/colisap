<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\AlertsWidget;
use App\Filament\Widgets\MemberStatusChart;
use App\Filament\Widgets\MemberStatusStats;
use App\Filament\Widgets\StatusByBranchChart;
use App\Models\Branch;
use App\Models\Member;
use App\Services\Access\Permissions;
use App\Services\Colisap\MonitoringService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    /**
     * @param  class-string  $chart
     * @return array<string, mixed>
     */
    private function chartData(string $chart): array
    {
        return (fn () => $this->getData())->call(app($chart));
    }

    public function test_status_cards_and_charts_reflect_the_database(): void
    {
        Member::factory()->count(3)->create();
        Member::factory()->waiting()->count(2)->create();
        Member::factory()->deceased()->create();
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(MemberStatusStats::class)
            ->assertSee('Total members')
            ->assertSeeInOrder(['Active', '3'])
            ->assertSeeInOrder(['Waiting', '2'])
            ->assertSeeInOrder(['Deceased', '1']);

        $data = $this->chartData(MemberStatusChart::class);

        $this->assertSame(
            ['Waiting' => 2, 'Active' => 3, 'Dormant' => 0, 'Deceased' => 1, 'Terminated' => 0, 'Withdrawn' => 0],
            array_combine($data['labels'], $data['datasets'][0]['data']),
        );
    }

    public function test_status_by_branch_covers_all_fifteen_branches(): void
    {
        Member::factory()->create(['branch_id' => Branch::where('name', 'Altavas')->value('id')]);
        $this->actingAsRole(Permissions::ADMIN);

        $data = $this->chartData(StatusByBranchChart::class);

        $this->assertCount(15, $data['labels']);
        $this->assertSame(1, $data['datasets'][1]['data'][array_search('Altavas', $data['labels'], true)]);
    }

    public function test_monitoring_figures_for_effectivity_beneficiaries_and_replenishment(): void
    {
        $this->travelTo('2026-06-30 08:00:00');
        Member::factory()->waiting()->create(['approval_date' => now()->subDays(160)]);
        Member::factory()->waiting()->create(['approval_date' => now()->subDays(100)]);
        Member::factory()->create(['savings_balance' => 100]);
        $monitoring = app(MonitoringService::class);

        $this->assertSame(1, $monitoring->becomingEffectiveWithin(30)->count());
        $this->assertSame(['Within 30 days' => 1, '31–60 days' => 0, '61–90 days' => 1, 'Over 90 days' => 0], $monitoring->effectivityBuckets());
        $this->assertSame(3, $monitoring->withoutBeneficiaries()->count());
        $this->assertSame(1, $monitoring->belowMinimumBalance()->count());
    }

    public function test_alerts_list_items_needing_attention_with_severity(): void
    {
        Member::factory()->create(['savings_balance' => 100]);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(AlertsWidget::class)
            ->assertSee('Savings below minimum')
            ->assertSee('Beneficiary information incomplete')
            ->assertSee('warning');
    }

    public function test_dashboard_shows_the_logo(): void
    {
        $this->actingAsRole(Permissions::CRS);

        $this->get('/admin')->assertOk()->assertSee('images/logo-192.png', escape: false);
    }
}
