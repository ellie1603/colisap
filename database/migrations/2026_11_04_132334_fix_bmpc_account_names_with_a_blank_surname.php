<?php

use App\Services\Masterlist\NameParser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BMPC members imported before Account Names were tidied on import: a name whose surname part the coop's file
 * left blank (", JEAN A NARCISO KIS") is put in the "SURNAME, FIRST MIDDLE" form.
 */
return new class extends Migration
{
    public function up(): void
    {
        $nameParser = app(NameParser::class);

        DB::table('bmpc_members')
            ->where('account_name', 'like', ',%')
            ->orWhere('account_name', 'like', ' ,%')
            ->orderBy('id')
            ->each(function (object $member) use ($nameParser): void {
                DB::table('bmpc_members')
                    ->where('id', $member->id)
                    ->update(['account_name' => $nameParser->surnameFirst($member->account_name) ?: null]);
            });
    }

    public function down(): void
    {
        // The original spelling is not kept; the next import of the coop's file writes the same tidy form.
    }
};
