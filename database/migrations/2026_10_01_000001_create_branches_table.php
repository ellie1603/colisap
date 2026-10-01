<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Official COLISAP branches (master records).
     */
    private const BRANCHES = [
        'Barbaza', 'Culasi', 'Sibalom', 'Guinsang-an', 'San Jose', 'Balasan', 'Barotac Viejo', 'Caticlan',
        'Molo', 'Kalibo', 'Janiuay', 'Calinog', 'Sara', 'President Roxas', 'Altavas',
    ];

    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 20)->nullable()->unique();
            $table->json('aliases')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        DB::table('branches')->insert(array_map(fn (string $name, int $index) => [
            'name' => $name,
            'code' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 3)).($index + 1),
            'sort_order' => $index + 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::BRANCHES, array_keys(self::BRANCHES)));
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
