<?php

use App\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Branches carry the cooperative's official three-digit codes (the first three digits of a member's
 * Acct. Number), in that order. Guinsang-an is not a COLISAP branch and is removed; if it ever holds
 * members it is only switched off, so no member loses their branch.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const CODES = [
        'Barbaza' => '001',
        'Culasi' => '002',
        'Sibalom' => '003',
        'San Jose' => '004',
        'Balasan' => '005',
        'Barotac Viejo' => '006',
        'Caticlan' => '007',
        'Molo' => '008',
        'Kalibo' => '009',
        'Janiuay' => '010',
        'Calinog' => '011',
        'Sara' => '012',
        'President Roxas' => '013',
        'Altavas' => '014',
    ];

    private const REMOVED = 'Guinsang-an';

    public function up(): void
    {
        $removed = DB::table('branches')->where('name', self::REMOVED)->first();

        if ($removed !== null) {
            DB::table('members')->where('branch_id', $removed->id)->exists()
                ? DB::table('branches')->where('id', $removed->id)->update(['is_active' => false, 'sort_order' => 99])
                : DB::table('branches')->where('id', $removed->id)->delete();
        }

        foreach (self::CODES as $name => $code) {
            DB::table('branches')->where('name', $name)->update(['code' => $code, 'sort_order' => (int) $code]);
        }

        Branch::flushLookupCache();
    }

    public function down(): void
    {
        $order = ['Barbaza', 'Culasi', 'Sibalom', self::REMOVED, ...array_slice(array_keys(self::CODES), 3)];

        if (! DB::table('branches')->where('name', self::REMOVED)->exists()) {
            DB::table('branches')->insert(['name' => self::REMOVED, 'is_active' => true, 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach ($order as $index => $name) {
            DB::table('branches')->where('name', $name)->update([
                'code' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 3)).($index + 1),
                'sort_order' => $index + 1,
                'is_active' => true,
            ]);
        }

        Branch::flushLookupCache();
    }
};
