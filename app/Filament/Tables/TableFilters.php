<?php

namespace App\Filament\Tables;

use App\Models\Member;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The filters the modules share — transaction date, benefit category and segmentation — built the same way
 * everywhere, each a single dropdown, so they look and behave alike in each table's filter bar.
 */
class TableFilters
{
    /**
     * One dropdown of periods on a date column (the transaction date, or a member's last transaction date): one
     * pick instead of typing a from / to range. "None" is offered where the date may be missing.
     */
    public static function transactionDate(string $column, string $label = 'Transaction date', bool $canBeMissing = false): SelectFilter
    {
        return SelectFilter::make('transaction_date')
            ->label($label)
            ->placeholder('Any date')
            ->options(array_filter([
                '30' => 'Last 30 days',
                '90' => 'Last 3 months',
                'year' => 'This year',
                'over_90' => 'Over 3 months ago',
                'none' => $canBeMissing ? 'None' : null,
            ]))
            ->query(function (Builder $query, array $data) use ($column): Builder {
                $column = $query->qualifyColumn($column);
                $today = Carbon::today();

                return match ($data['value'] ?? null) {
                    '30' => $query->whereDate($column, '>=', $today->copy()->subDays(30)),
                    '90' => $query->whereDate($column, '>=', $today->copy()->subMonthsNoOverflow(3)),
                    'year' => $query->whereDate($column, '>=', $today->copy()->startOfYear()),
                    'over_90' => $query->whereDate($column, '<', $today->copy()->subMonthsNoOverflow(3)),
                    'none' => $query->whereNull($column),
                    default => $query,
                };
            });
    }

    public static function category(string $column = 'category'): SelectFilter
    {
        return SelectFilter::make('category')
            ->label('Category')
            ->placeholder('All categories')
            ->options(fn () => Member::categoryOptions())
            ->attribute($column);
    }

    /**
     * Diamond, Gold, Silver, or NA (no segmentation).
     */
    public static function segmentation(string $column = 'segment'): SelectFilter
    {
        return SelectFilter::make('segment')
            ->label('Segmentation')
            ->placeholder('All segmentations')
            ->options([...Member::SEGMENTS, 'na' => 'NA'])
            ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                null, '' => $query,
                'na' => $query->whereNull($query->qualifyColumn($column)),
                default => $query->where($query->qualifyColumn($column), $data['value']),
            });
    }
}
