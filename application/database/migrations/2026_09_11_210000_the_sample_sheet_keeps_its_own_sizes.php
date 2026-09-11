<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sizes a SAMPLE is made in.
 *
 * The sheet showed the order's own size list - 830 pieces across five sizes -
 * on the sample sheet as well, which is the batch's breakdown, not the
 * sample's. A sample is one piece per size, and which sizes get made is a
 * decision of its own: sometimes every size for a fitting, sometimes just the
 * one the client wants to see.
 *
 * Null means "the sizes the order asked for", so every sheet drawn before this
 * carries on reading exactly as it did until somebody edits it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->json('sample_sizes')->nullable()->after('extra_notes');
        });
    }

    public function down(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->dropColumn('sample_sizes');
        });
    }
};
