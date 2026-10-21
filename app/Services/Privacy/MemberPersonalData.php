<?php

namespace App\Services\Privacy;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\ImportRow;
use App\Models\Member;
use App\Models\MemberHistory;
use App\Models\ReplenishmentNotice;
use App\Models\SavingsTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Data Privacy Act requests about one member: a copy of everything COLISAP holds about them (right of
 * access), and permanent erasure of an archived member (right to erasure).
 */
class MemberPersonalData
{
    /**
     * Everything stored about the member, as one document they can be given.
     *
     * @return array<string, mixed>
     */
    public function export(Member $member): array
    {
        $member->loadMissing('branch');

        $data = [
            'generated_at' => now()->toIso8601String(),
            'generated_by' => 'COLISAP — Barbaza Multi-Purpose Cooperative',
            'member' => [...$member->attributesToArray(), 'branch' => $member->branch?->name],
            'applications' => $member->applications()->withoutGlobalScopes()->get()->map->attributesToArray()->all(),
            'savings_transactions' => $member->savingsTransactions()->get()->map->attributesToArray()->all(),
            'replenishment_notices' => $member->replenishmentNotices()->get()->map->attributesToArray()->all(),
            'change_history' => $member->histories()->reorder('id')->get(['field', 'old_value', 'new_value', 'reason', 'source', 'created_at'])->map->attributesToArray()->all(),
            'activity' => AuditLog::query()
                ->where('auditable_type', Member::class)->where('auditable_id', $member->id)
                ->orderBy('id')->get(['action', 'old_values', 'new_values', 'reason', 'created_at'])
                ->map->attributesToArray()->all(),
        ];

        $member->recordAudit('personal_data_exported', null, null, 'Copy of personal data exported');

        return $data;
    }

    /**
     * Permanently removes an archived member and every record that holds their personal data. Only the fact that
     * an erasure happened is kept (with the Acct. Number and reason), so the cooperative can show it was done.
     */
    public function erase(Member $member, string $reason): void
    {
        if (! $member->trashed()) {
            throw new RuntimeException('Archive the member first. Only archived members can be erased.');
        }

        $accountNo = $member->account_no;
        $document = $member->withdrawal_document;

        DB::transaction(function () use ($member, $accountNo, $reason) {
            // Applications filed under this Acct. Number but never linked (e.g. rejected) hold the same details.
            $applications = Application::query()->withoutGlobalScopes()
                ->where(fn ($query) => $query->where('member_id', $member->id)->orWhere('account_no', $accountNo))
                ->pluck('id');

            $related = [
                Application::class => $applications,
                SavingsTransaction::class => $member->savingsTransactions()->pluck('id'),
                ReplenishmentNotice::class => $member->replenishmentNotices()->pluck('id'),
                MemberHistory::class => $member->histories()->pluck('id'),
                Member::class => collect([$member->id]),
            ];

            AuditLog::withoutRecording(function () use ($member, $accountNo, $applications) {
                Application::query()->withoutGlobalScopes()->whereKey($applications)->toBase()->delete();
                ImportRow::query()->where('member_id', $member->id)->orWhere('account_no', $accountNo)->delete();
                $member->forceDelete();
            });

            // Log entries copy the member's details (names, birthdate, numbers), so they go too.
            foreach ($related as $type => $ids) {
                AuditLog::query()->where('auditable_type', $type)->whereIn('auditable_id', $ids)->delete();
            }

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'member_erased',
                'module' => 'Member',
                'auditable_type' => Member::class,
                'auditable_id' => 0,
                'new_values' => ['account_no' => $accountNo],
                'reason' => $reason,
                'description' => 'Member permanently erased on request',
            ]);
        });

        if ($document) {
            Storage::disk('local')->delete($document);
        }
    }
}
