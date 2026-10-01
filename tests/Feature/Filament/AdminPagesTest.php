<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Monitoring;
use App\Filament\Pages\PolicySettings;
use App\Filament\Pages\Reports;
use App\Filament\Resources\ApplicationResource;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\BranchResource;
use App\Filament\Resources\ClaimResource;
use App\Filament\Resources\ClaimResource\Pages\ListClaims;
use App\Filament\Resources\ContributionResource;
use App\Filament\Resources\ImportBatchResource;
use App\Filament\Resources\MemberResource;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\UserResource;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Claim;
use App\Models\ImportBatch;
use App\Models\Member;
use App\Models\ReplenishmentNotice;
use App\Services\Access\Permissions;
use App\Services\Colisap\ContributionService;
use App\Services\Reports\ReportService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    private function seedOperationalData(): Claim
    {
        Member::factory()->count(3)->create();
        Member::factory()->waiting()->create();
        Member::factory()->dormant()->create();
        Member::factory()->create(['savings_balance' => 100, 'category_upgrade_requested_at' => now()->subDays(100)]);
        ReplenishmentNotice::factory()->create();
        Application::factory()->create();
        ImportBatch::create(['file_name' => 'masterlist.xlsx', 'as_of_date' => now(), 'status' => 'completed', 'uploaded_by' => auth()->id()]);

        $claim = Claim::factory()->verified()->create(['status' => 'under_review']);
        $claim->approve(auth()->user());
        app(ContributionService::class)->generateForClaim($claim->fresh());

        return $claim->fresh();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pages(): array
    {
        return [
            'dashboard' => [Dashboard::class],
            'monitoring effectivity' => [Monitoring::class.'?tab=effectivity'],
            'monitoring upgrades' => [Monitoring::class.'?tab=upgrades'],
            'monitoring replenishment' => [Monitoring::class.'?tab=replenishment'],
            'monitoring dormancy' => [Monitoring::class.'?tab=dormancy'],
            'monitoring beneficiaries' => [Monitoring::class.'?tab=beneficiaries'],
            'reports' => [Reports::class],
            'policy settings' => [PolicySettings::class],
            'members' => [MemberResource::class.'@index'],
            'member create' => [MemberResource::class.'@create'],
            'member import' => [MemberResource::class.'@import'],
            'beneficiaries' => [BeneficiaryResource::class.'@index'],
            'beneficiary create' => [BeneficiaryResource::class.'@create'],
            'applications' => [ApplicationResource::class.'@index'],
            'application create' => [ApplicationResource::class.'@create'],
            'claims' => [ClaimResource::class.'@index'],
            'claim create' => [ClaimResource::class.'@create'],
            'contributions' => [ContributionResource::class.'@index'],
            'import history' => [ImportBatchResource::class.'@index'],
            'branches' => [BranchResource::class.'@index'],
            'users' => [UserResource::class.'@index'],
            'roles' => [RoleResource::class.'@index'],
            'audit logs' => [AuditLogResource::class.'@index'],
        ];
    }

    #[DataProvider('pages')]
    public function test_page_renders_with_operational_data(string $page): void
    {
        $this->actingAsRole(Permissions::SUPER_ADMIN);
        $this->seedOperationalData();

        [$target, $query] = array_pad(explode('?', $page), 2, null);
        [$class, $name] = array_pad(explode('@', $target), 2, null);
        $url = $name ? $class::getUrl($name) : $class::getUrl();

        $this->get($url.($query ? "?{$query}" : ''))->assertOk();
    }

    public function test_record_pages_render(): void
    {
        $this->actingAsRole(Permissions::SUPER_ADMIN);
        $claim = $this->seedOperationalData();
        $member = $claim->member;

        foreach ([
            MemberResource::getUrl('view', ['record' => Member::where('status', 'active')->first()]),
            MemberResource::getUrl('edit', ['record' => Member::where('status', 'active')->first()]),
            MemberResource::getUrl('view', ['record' => $member]),
            ClaimResource::getUrl('view', ['record' => $claim]),
            ApplicationResource::getUrl('edit', ['record' => Application::first()]),
            BeneficiaryResource::getUrl('edit', ['record' => $claim->beneficiary]),
            ImportBatchResource::getUrl('view', ['record' => ImportBatch::first()]),
            BranchResource::getUrl('edit', ['record' => Branch::first()]),
            RoleResource::getUrl('edit', ['record' => Role::findByName(Permissions::CRS)]),
            AuditLogResource::getUrl('view', ['record' => AuditLog::first()]),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_certificate_and_claim_print_as_pdf(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $claim = $this->seedOperationalData();

        $this->get(route('members.certificate', Member::where('status', 'active')->first()))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('claims.print', $claim))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reports(): array
    {
        return collect(ReportService::REPORTS)->keys()->mapWithKeys(fn (string $key) => [$key => [$key]])->all();
    }

    #[DataProvider('reports')]
    public function test_every_report_exports_to_excel_and_pdf(string $report): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $this->seedOperationalData();

        Livewire::test(Reports::class)
            ->fillForm(['report' => $report])
            ->call('export', 'xlsx')
            ->assertFileDownloaded();

        Livewire::test(Reports::class)
            ->fillForm(['report' => $report])
            ->call('export', 'pdf')
            ->assertFileDownloaded();
    }

    public function test_claims_can_be_searched_by_member_name(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $claim = Claim::factory()->create();
        $claim->member->update(['first_name' => 'Zenaida', 'last_name' => 'Villanueva', 'account_name' => 'VILLANUEVA, ZENAIDA']);
        $other = Claim::factory()->create();

        Livewire::test(ListClaims::class)
            ->searchTable('zenaida villanueva')
            ->assertCanSeeTableRecords([$claim])
            ->assertCanNotSeeTableRecords([$other]);
    }
}
