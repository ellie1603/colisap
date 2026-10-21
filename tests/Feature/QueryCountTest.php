<?php

namespace Tests\Feature;

use App\Filament\Pages\Monitoring;
use App\Filament\Resources\ApplicationResource;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\MemberResource;
use App\Models\Application;
use App\Models\Member;
use App\Models\ReplenishmentNotice;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;

/**
 * Pages must not run one more query for every row they show ("N+1"), which is what makes a list slow
 * once the real masterlist is loaded. Each page is opened with few and with many rows; the number of
 * database queries must stay the same.
 */
class QueryCountTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function pages(): array
    {
        return [
            'dashboard' => ['/admin'],
            'members' => ['members'],
            'applications' => ['applications'],
            'monitoring · effectivity' => ['monitoring:effectivity'],
            'monitoring · replenishment' => ['monitoring:replenishment'],
            'audit log' => ['audit'],
        ];
    }

    private function url(string $page): string
    {
        return match (true) {
            $page === 'members' => MemberResource::getUrl('index'),
            $page === 'applications' => ApplicationResource::getUrl('index'),
            $page === 'audit' => AuditLogResource::getUrl('index'),
            str_starts_with($page, 'monitoring:') => Monitoring::getUrl(['tab' => substr($page, 11)]),
            default => $page,
        };
    }

    private function addRows(int $count): void
    {
        foreach (Member::factory()->count($count)->waiting()->create(['approval_date' => now()->subDays(170)]) as $member) {
            Application::factory()->create(['member_id' => $member->id]);
            ReplenishmentNotice::factory()->create(['member_id' => $member->id]);
        }
    }

    private function queriesFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    #[DataProvider('pages')]
    public function test_the_number_of_queries_does_not_grow_with_the_rows_shown(string $page): void
    {
        $this->actingAsRole(Permissions::ADMIN);

        $this->addRows(2);
        $this->queriesFor($this->url($page));
        $few = $this->queriesFor($this->url($page));

        $this->addRows(12);
        $many = $this->queriesFor($this->url($page));

        $this->assertLessThanOrEqual($few + 2, $many, "{$page}: {$few} queries with few rows, {$many} with many.");
        $this->assertLessThan(80, $many, "{$page} runs {$many} queries.");
    }
}
