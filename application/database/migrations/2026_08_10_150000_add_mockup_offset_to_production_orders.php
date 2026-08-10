<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the design sits on the job order sheet.
 *
 * It was kept per browser, which meant dragging it clear of the description on
 * the office PC did nothing for the artist at home — and the sheet prints where
 * it was dragged, so the two of them printed differently. It belongs to the
 * order, not to whoever happens to be looking at it.
 */
return new class extends Migration
{
    private const FIELDS = ['mockup_offset_x', 'mockup_offset_y'];

    public function up(): void
    {
        $missing = array_filter(
            self::FIELDS,
            fn ($column) => ! Schema::hasColumn('production_orders', $column)
        );

        if ($missing === []) {
            return;
        }

        Schema::table('production_orders', function (Blueprint $table) use ($missing) {
            foreach ($missing as $column) {
                // Whole pixels, and it can be dragged up or left of where it
                // started, so signed.
                $table->integer($column)->default(0);
            }
        });
    }

    public function down(): void
    {
        $present = array_filter(
            self::FIELDS,
            fn ($column) => Schema::hasColumn('production_orders', $column)
        );

        if ($present === []) {
            return;
        }

        Schema::table('production_orders', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }
};
