<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What the job orders call a cotton hoodie, the shelf calls terry cotton.
 *
 * IC2026-01175 asked for "COTTON HOODIE" and the ready-made shelf keeps
 * "TERRY COTTON BLACK CUT & SEW" in nine sizes, so nothing matched and the
 * desk was told its own stock was not on its shelf. Five days, fourteen pieces
 * asked for, nine already handed over by somebody working round the system.
 *
 * Confirmed by the shop on 2026-09-23. The sizes take care of themselves: the
 * pair names the family and MaterialName::sameFamily() reaches every size
 * under it.
 */
return new class extends Migration
{
    private const PAIR = ['COTTON HOODIE', 'TERRY COTTON BLACK CUT & SEW'];

    public function up(): void
    {
        $now = now();

        DB::table('material_aliases')->insert([
            'alias' => self::PAIR[0],
            'material' => self::PAIR[1],
            'added_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('material_aliases')
            ->where('alias', self::PAIR[0])
            ->where('material', self::PAIR[1])
            ->delete();
    }
};
