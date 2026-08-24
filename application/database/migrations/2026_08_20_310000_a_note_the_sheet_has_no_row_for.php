<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Text blocks the artist added themselves.
 *
 * The sheet names the things every job has. A job with something to say that
 * the sheet has no row for — a note against one panel, a measurement beside a
 * flat — had nowhere to say it, so it went in the additional notes at the
 * bottom, far from the thing it was about. A block can be added and dragged to
 * where it belongs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->json('extra_notes')->nullable()->after('box_positions');
        });
    }

    public function down(): void
    {
        Schema::table('tech_packs', fn (Blueprint $t) => $t->dropColumn('extra_notes'));
    }
};
