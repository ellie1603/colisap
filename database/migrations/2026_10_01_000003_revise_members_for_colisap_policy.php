<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->renameColumn('member_no', 'account_no');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->renameColumn('membership_date', 'approval_date');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->string('status', 20)->default('waiting')->change();
            $table->date('approval_date')->nullable()->change();
        });

        Schema::table('members', function (Blueprint $table) {
            $table->string('account_name')->nullable()->after('account_no');
            $table->foreignId('branch_id')->nullable()->after('account_name')->constrained('branches')->restrictOnDelete();
            $table->char('segment', 1)->nullable()->after('category')->comment('D=Diamond, G=Gold, S=Silver, R=Regular, NULL=Unassigned');
            $table->date('application_date')->nullable()->after('segment');
            $table->decimal('savings_balance', 14, 2)->default(0)->after('category_upgrade_requested_at');
            $table->date('savings_balance_as_of')->nullable()->after('savings_balance');
            $table->string('savings_account_status', 30)->nullable()->after('savings_balance_as_of')->comment('Source flag from the masterlist, e.g. dormant-txn, dormant-bal');
            $table->date('last_activity_date')->nullable()->after('savings_account_status');
            $table->date('dormant_since')->nullable()->after('last_activity_date');
            $table->date('terminated_at')->nullable()->after('date_deceased');
            $table->string('termination_reason')->nullable()->after('terminated_at');
            $table->date('withdrawn_at')->nullable()->after('termination_reason');
            $table->text('withdrawal_reason')->nullable()->after('withdrawn_at');
            $table->string('withdrawal_document')->nullable()->after('withdrawal_reason');
            $table->unsignedBigInteger('import_batch_id')->nullable()->after('remarks');
            $table->string('source_sheet')->nullable()->after('import_batch_id');
            $table->unsignedInteger('source_row')->nullable()->after('source_sheet');
            $table->json('source_values')->nullable()->after('source_row');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();

            $table->index('segment');
            $table->index('category');
            $table->index('application_date');
            $table->index('approval_date');
            $table->index('last_activity_date');
            $table->index('savings_balance');
        });

        foreach (DB::table('branches')->get(['id', 'name']) as $branch) {
            DB::table('members')->whereRaw('LOWER(TRIM(branch)) = ?', [mb_strtolower($branch->name)])->update(['branch_id' => $branch->id]);
        }

        foreach (DB::table('members')->whereNull('account_name')->get(['id', 'first_name', 'middle_name', 'last_name']) as $member) {
            DB::table('members')->where('id', $member->id)->update([
                'account_name' => mb_strtoupper(trim("{$member->last_name}, {$member->first_name} {$member->middle_name}")),
            ]);
        }

        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex(['branch']);
            $table->dropColumn('branch');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('branch')->nullable()->index();
        });

        Schema::table('members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropIndex(['segment']);
            $table->dropIndex(['category']);
            $table->dropIndex(['application_date']);
            $table->dropIndex(['approval_date']);
            $table->dropIndex(['last_activity_date']);
            $table->dropIndex(['savings_balance']);
            $table->dropColumn([
                'account_name', 'segment', 'application_date', 'savings_balance', 'savings_balance_as_of', 'savings_account_status',
                'last_activity_date', 'dormant_since', 'terminated_at', 'termination_reason', 'withdrawn_at', 'withdrawal_reason',
                'withdrawal_document', 'import_batch_id', 'source_sheet', 'source_row', 'source_values',
            ]);
        });

        Schema::table('members', function (Blueprint $table) {
            $table->renameColumn('approval_date', 'membership_date');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->renameColumn('account_no', 'member_no');
        });
    }
};
