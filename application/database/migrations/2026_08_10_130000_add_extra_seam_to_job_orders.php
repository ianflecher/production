<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last seam group on the paper form only fills three of its four columns.
 * The fourth is left blank with a "Sewer:" under it — a spare column to write
 * in whatever this particular garment needed. Give it the same three boxes so
 * it can actually be used instead of printing as dead space.
 *
 * Adds only what is missing, for the same reason as the migration before it:
 * a run cut off part way must be able to finish itself rather than jamming
 * every future deploy on "duplicate column".
 */
return new class extends Migration
{
    private const FIELDS = [
        'extra_seam_label',   // what the seam is
        'extra_seam_note',    // the blank line under it
        'extra_seam_sewer',   // who sewed it
    ];

    public function up(): void
    {
        $missing = array_filter(
            self::FIELDS,
            fn ($column) => ! Schema::hasColumn('job_orders', $column)
        );

        if ($missing === []) {
            return;
        }

        Schema::table('job_orders', function (Blueprint $table) use ($missing) {
            foreach ($missing as $column) {
                $table->string($column)->nullable();
            }
        });
    }

    public function down(): void
    {
        $present = array_filter(
            self::FIELDS,
            fn ($column) => Schema::hasColumn('job_orders', $column)
        );

        if ($present === []) {
            return;
        }

        Schema::table('job_orders', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }
};
