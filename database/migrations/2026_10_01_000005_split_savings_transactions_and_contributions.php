<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The old "contributions" table held savings deposits; it becomes the savings ledger.
        Schema::rename('contributions', 'savings_transactions');

        // Foreign key names are schema-wide in MySQL; rename them so the new contributions table can reuse the names.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('savings_transactions', function (Blueprint $table) {
                $table->dropForeign('contributions_member_id_foreign');
                $table->dropForeign('contributions_recorded_by_foreign');
            });

            Schema::table('savings_transactions', function (Blueprint $table) {
                $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
                $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->renameColumn('contribution_date', 'transaction_date');
        });

        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->string('type', 30)->default('deposit')->after('member_id')->comment('deposit, withdrawal, contribution_deduction, import_adjustment');
            $table->decimal('balance_after', 14, 2)->nullable()->after('amount');
            $table->unsignedBigInteger('import_batch_id')->nullable()->after('remarks');
        });

        $totals = DB::table('savings_transactions')
            ->select('member_id', DB::raw('SUM(amount) as total'), DB::raw('MAX(transaction_date) as last_date'))
            ->groupBy('member_id')
            ->get();

        foreach ($totals as $row) {
            DB::table('members')->where('id', $row->member_id)->update([
                'savings_balance' => $row->total,
                'last_activity_date' => $row->last_date,
            ]);
        }

        // Mortuary-claim contributions (Policy III.2 and VI).
        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('claim_id')->nullable()->constrained('claims')->nullOnDelete();
            $table->decimal('amount', 12, 2)->comment('Total contribution due for this claim');
            $table->decimal('member_share', 12, 2)->default(0);
            $table->decimal('coop_share', 12, 2)->default(0)->comment('Portion shouldered by Barbaza MPC (Diamond/Gold)');
            $table->char('segment', 1)->nullable()->comment('Member segment at the time of the claim');
            $table->date('contribution_date');
            $table->string('reference_no')->nullable();
            $table->string('status', 20)->default('pending')->comment('pending, deducted, waived');
            $table->foreignId('savings_transaction_id')->nullable()->constrained('savings_transactions')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['member_id', 'claim_id']);
            $table->index(['claim_id', 'status']);
            $table->index('contribution_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contributions');

        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->dropColumn(['type', 'balance_after', 'import_batch_id']);
        });

        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->renameColumn('transaction_date', 'contribution_date');
        });

        Schema::rename('savings_transactions', 'contributions');
    }
};
