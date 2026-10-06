<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Monitoring;
use App\Filament\Pages\PolicySettings;
use App\Filament\Pages\Reports;
use App\Filament\Resources\ApplicationResource;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\BranchResource;
use App\Filament\Resources\ImportBatchResource;
use App\Filament\Resources\MemberResource;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\UserResource;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\Member;
use App\Models\ReplenishmentNotice;
use App\Services\Access\Permissions;
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

    private function seedOperationalData(): void
    {
        Member::factory()->count(3)->create();
        Member::factory()->waiting()->create();
        Member::factory()->dormant()->create();
        Member::factory()->create(['savings_balance' => 100, 'category_upgrade_requested_at' => now()->subDays(100)]);
        ReplenishmentNotice::factory()->create();
        Application::factory()->create();
        ImportBatch::create(['file_name' => 'masterlist.xlsx', 'as_of_date' => now(), 'status' => 'completed', 'uploaded_by' => auth()->id()]);

        Member::factory()->deceased()->create();
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
            'reports' => [Reports::class],
            'policy settings' => [PolicySettings::class],
            'members' => [MemberResource::class.'@index'],
            'member create' => [MemberResource::class.'@create'],
            'member import' => [MemberResource::class.'@import'],
            'applications' => [ApplicationResource::class.'@index'],
            'application create' => [ApplicationResource::class.'@create'],
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
        $this->actingAsRole(Permissions::ADMIN);
        $this->seedOperationalData();

        [$target, $query] = array_pad(explode('?', $page), 2, null);
        [$class, $name] = array_pad(explode('@', $target), 2, null);
        $url = $name ? $class::getUrl($name) : $class::getUrl();

        $this->get($url.($query ? "?{$query}" : ''))->assertOk();
    }

    public function test_record_pages_render(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $this->seedOperationalData();
        $member = Member::where('status', 'waiting')->first();

        foreach ([
            MemberResource::getUrl('view', ['record' => Member::where('status', 'active')->first()]),
            MemberResource::getUrl('edit', ['record' => Member::where('status', 'active')->first()]),
            MemberResource::getUrl('view', ['record' => $member]),
            MemberResource::getUrl('view', ['record' => Member::where('status', 'deceased')->first()]),
            ApplicationResource::getUrl('edit', ['record' => Application::first()]),
            ImportBatchResource::getUrl('view', ['record' => ImportBatch::first()]),
            BranchResource::getUrl('edit', ['record' => Branch::first()]),
            RoleResource::getUrl('edit', ['record' => Role::findByName(Permissions::CRS)]),
            AuditLogResource::getUrl('view', ['record' => AuditLog::first()]),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_certificate_prints_as_pdf(): void
    {
        $this->actingAsRole(Permissions::ADMIN);
        $this->seedOperationalData();

        $this->get(route('members.certificate', Member::where('status', 'active')->first()))->assertOk()->assertHeader('content-type', 'application/pdf');
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
}
