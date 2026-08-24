<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the floor writes down, in the floor's own words.
 *
 * The sewing record was twenty-one boxes named after seams — neckbond, flatbed,
 * topping side, pipping — each wanting a sewer and a thread code. Every garment
 * is different, so most of them were blank on most jobs and the ones that
 * mattered were somewhere in the grid. Five slots: what they did, and who did
 * it. The quality check gets one of the same.
 *
 * One JSON column rather than ten more: job_orders is already close to InnoDB's
 * row limit, which is why the tech pack was moved to its own table.
 *
 * The old seam columns are left where they are. Jobs already sewn have their
 * record in them, and that is the trail a fault is traced back through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->json('sewing_log')->nullable()->after('sewer_notes');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', fn (Blueprint $t) => $t->dropColumn('sewing_log'));
    }
};
