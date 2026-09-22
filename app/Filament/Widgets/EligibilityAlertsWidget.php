<?php

namespace App\Filament\Widgets;

use App\Models\EligibilityRule;
use App\Models\Member;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class EligibilityAlertsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Eligibility Alerts';

    public function table(Table $table): Table
    {
        $rule = EligibilityRule::where('is_active', true)->first();

        return $table
            ->query(function () use ($rule) {
                $query = Member::query()->where('status', 'active');

                if (! $rule) {
                    return $query->whereRaw('1 = 0');
                }

                $cutoffDate = now()->subMonths($rule->min_membership_months)->toDateString();

                return $query->where(function (Builder $q) use ($cutoffDate, $rule) {
                    $q->where('membership_date', '>', $cutoffDate)
                        ->orWhereRaw(
                            '(select coalesce(sum(amount), 0) from contributions where contributions.member_id = members.id) < ?',
                            [$rule->min_contribution_balance]
                        );
                });
            })
            ->columns([
                Tables\Columns\TextColumn::make('member_no')->label('Member No.'),
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Name')
                    ->getStateUsing(fn (Member $record) => $record->fullName()),
                Tables\Columns\TextColumn::make('membership_date')
                    ->date()
                    ->label('Joined'),
                Tables\Columns\TextColumn::make('balance')
                    ->label('Contribution Balance')
                    ->getStateUsing(fn (Member $record) => '₱'.number_format($record->contributionBalance(), 2)),
                Tables\Columns\TextColumn::make('reason')
                    ->label('Flag')
                    ->badge()
                    ->color('warning')
                    ->getStateUsing(function (Member $record) {
                        $rule = EligibilityRule::where('is_active', true)->first();

                        if (! $rule) {
                            return 'No active rule';
                        }

                        $reasons = [];

                        if ($record->membershipMonths() < $rule->min_membership_months) {
                            $reasons[] = 'Membership tenure';
                        }

                        if ($record->contributionBalance() < $rule->min_contribution_balance) {
                            $reasons[] = 'Contribution balance';
                        }

                        return implode(', ', $reasons) ?: 'At risk';
                    }),
            ])
            ->paginated([5, 10, 25])
            ->defaultSort('membership_date', 'desc');
    }
}
