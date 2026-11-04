<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BMPC members are identified by their CIF Key (the coop's unique member number, formerly stored as account_no).
 * The savings number is the savings account the balance belongs to, and is what a COLISAP member's Acct. Number
 * matches. Status and Remarks are kept as the coop's file writes them. Name parts are worked out from the Account
 * Name, so they may be empty.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const REMARK_LABELS = [
        'deactivated' => 'Deactivated',
        'deceased' => 'Deceased',
        'dormant-bal' => 'Dormant-bal',
        'dormant-txn' => 'Dormant-txn',
    ];

    public function up(): void
    {
        Schema::table('bmpc_members', function (Blueprint $table) {
            $table->renameColumn('account_no', 'cif_key');
        });

        Schema::table('bmpc_members', function (Blueprint $table) {
            $table->string('savings_no', 30)->nullable()->after('cif_key')->index();
            $table->string('status', 100)->nullable()->after('category');
            $table->string('remarks')->nullable()->comment(null)->change();
            $table->string('last_name')->nullable()->change();
            $table->string('first_name')->nullable()->change();
        });

        foreach (self::REMARK_LABELS as $value => $label) {
            DB::table('bmpc_members')->where('remarks', $value)->update(['remarks' => $label]);
        }
    }

    public function down(): void
    {
        foreach (self::REMARK_LABELS as $value => $label) {
            DB::table('bmpc_members')->where('remarks', $label)->update(['remarks' => $value]);
        }

        Schema::table('bmpc_members', function (Blueprint $table) {
            $table->dropIndex(['savings_no']);
            $table->dropColumn(['savings_no', 'status']);
        });

        Schema::table('bmpc_members', function (Blueprint $table) {
            $table->renameColumn('cif_key', 'account_no');
        });
    }
};
