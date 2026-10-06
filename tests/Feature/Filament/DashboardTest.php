<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Monitoring;
use App\Filament\Resources\MemberResource;
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

    public function test_the_status_summary_is_limited_to_four_cards_linking_to_the_member_list(): void
    {
        Member::factory()->dormant()->create();
        $this->actingAsRole(Permissions::CRS);

        $html = Livewire::test(MemberStatusStats::class)->html();

        $this->assertSame(4, substr_count($html, 'class="colisap-kpi colisap-kpi--'));
        $this->assertStringContainsString('activeTab=dormant', $html);
    }

    public function test_the_top_of_the_dashboard_is_rendered_in_the_first_response_not_lazy_loaded(): void
    {
        Member::factory()->create();
        $this->actingAsRole(Permissions::CRS);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('class="colisap-kpi colisap-kpi--total"', escape: false)
            ->assertSee('Action center')
            ->assertSee('New approvals');
    }

    public function test_status_by_branch_covers_all_fifteen_branches(): void
    {
        Member::factory()->create(['branch_id' => Branch::where('name', 'Altavas')->value('id')]);
        $this->actingAsRole(Permissions::ADMIN);

        $data = $this->chartData(StatusByBranchChart::class);

        $this->assertCount(15, $data['labels']);
        $this->assertSame(1, $data['datasets'][1]['data'][array_search('Altavas', $data['labels'], true)]);
    }

    public function test_monitoring_figures_for_effectivity_and_replenishment(): void
    {
        $this->travelTo('2026-06-30 08:00:00');
        Member::factory()->waiting()->create(['approval_date' => now()->subDays(160)]);
        Member::factory()->waiting()->create(['approval_date' => now()->subDays(100)]);
        Member::factory()->create(['savings_balance' => 100]);
        $monitoring = app(MonitoringService::class);

        $this->assertSame(1, $monitoring->becomingEffectiveWithin(30)->count());
        $this->assertSame(['Within 30 days' => 1, '31–60 days' => 0, '61–90 days' => 1, 'Over 90 days' => 0], $monitoring->effectivityBuckets());
        $this->assertSame(1, $monitoring->belowMinimumBalance()->count());
    }

    public function test_alerts_list_items_needing_attention_with_severity(): void
    {
        Member::factory()->create(['savings_balance' => 100]);
        $this->actingAsRole(Permissions::CRS);

        Livewire::test(AlertsWidget::class)
            ->assertSee('Savings below minimum')
            ->assertDontSee('Beneficiary')
            ->assertSee('warning');
    }

    public function test_the_panel_chrome_has_a_sidebar_logout_and_a_topbar_theme_toggle(): void
    {
        $this->actingAsRole(Permissions::CRS);

        $this->get('/admin')
            ->assertOk()
            ->assertSeeInOrder(['colisap-sidebar-logout', 'action="'.filament()->getLogoutUrl().'"', 'Logout'], escape: false)
            ->assertSee('class="colisap-theme-toggle"', escape: false)
            ->assertDontSee('toggleCollapsedGroup', escape: false);
    }

    public function test_the_topbar_back_button_is_rendered_hidden_so_only_detail_pages_reveal_it(): void
    {
        $this->actingAsRole(Permissions::CRS);

        foreach (['/admin', Monitoring::getUrl(), MemberResource::getUrl('index')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('class="colisap-back" data-colisap-back hidden', escape: false);
        }
    }

    public function test_signing_out_from_the_sidebar_ends_the_session(): void
    {
        $this->actingAsRole(Permissions::CRS);

        $this->post(filament()->getLogoutUrl())->assertRedirect();

        $this->assertGuest();
    }

    public function test_dashboard_shows_the_logo(): void
    {
        $this->actingAsRole(Permissions::CRS);

        $this->get('/admin')->assertOk()->assertSee('images/logo-192.png', escape: false);
    }
}
