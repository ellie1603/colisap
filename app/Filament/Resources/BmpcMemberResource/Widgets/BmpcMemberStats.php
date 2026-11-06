<?php

namespace App\Filament\Resources\BmpcMemberResource\Widgets;

use App\Filament\Resources\BmpcMemberResource\Pages\ListBmpcMembers;
use App\Models\Member;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\Widget;

/**
 * The four cards above the BMPC member list. They count the members the table shows, so a branch filter or a
 * search narrows the cards too. A BMPC member is "in COLISAP" when a participating COLISAP member's Savings Number
 * is their savings number.
 */
class BmpcMemberStats extends Widget
{
    use InteractsWithPageTable;

    protected static string $view = 'filament.resources.bmpc-member-resource.widgets.bmpc-member-stats';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getTablePage(): string
    {
        return ListBmpcMembers::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $colisapAccounts = Member::query()->participating()->whereNotNull('account_no')->select('account_no');

        // One pass over the filtered list instead of a query per card.
        $totals = $this->getPageTableQuery()
            ->reorder()
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(bmpc_members.savings_balance), 0) as savings')
            ->selectRaw("sum(case when bmpc_members.savings_no in ({$colisapAccounts->toSql()}) then 1 else 0 end) as in_colisap", $colisapAccounts->getBindings())
            ->selectRaw('sum(case when bmpc_members.remarks is not null then 1 else 0 end) as with_remarks')
            ->first();

        $total = (int) $totals->total;
        $inColisap = (int) $totals->in_colisap;
        $notInColisap = $total - $inColisap;
        $withRemarks = (int) $totals->with_remarks;
        $percentOf = fn (int $value): int => $total > 0 ? (int) round($value / $total * 100) : 0;

        return [
            'total' => $total,
            'savings' => (float) $totals->savings,
            'breakdown' => [
                ['key' => 'active', 'label' => 'In COLISAP', 'count' => $inColisap, 'percent' => $total > 0 ? $inColisap / $total * 100 : 0],
                ['key' => 'waiting', 'label' => 'Not yet in COLISAP', 'count' => $notInColisap, 'percent' => $total > 0 ? $notInColisap / $total * 100 : 0],
            ],
            'cards' => [
                [
                    'key' => 'active',
                    'label' => 'COLISAP members',
                    'icon' => 'heroicon-o-shield-check',
                    'count' => $inColisap,
                    'percent' => $percentOf($inColisap),
                ],
                [
                    'key' => 'waiting',
                    'label' => 'Not yet in COLISAP',
                    'icon' => 'heroicon-o-user-plus',
                    'count' => $notInColisap,
                    'percent' => $percentOf($notInColisap),
                ],
                [
                    'key' => 'dormant',
                    'label' => 'With remarks',
                    'icon' => 'heroicon-o-exclamation-triangle',
                    'count' => $withRemarks,
                    'percent' => $percentOf($withRemarks),
                ],
            ],
        ];
    }
}
