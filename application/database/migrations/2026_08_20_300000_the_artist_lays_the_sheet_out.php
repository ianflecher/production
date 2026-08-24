<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the artist dragged each box to.
 *
 * The pack was a fixed layout: every job got its boxes in the same places, and
 * a garment that wanted the artwork beside the flat instead of under it had no
 * way to say so. Boxes and text blocks can be moved now, and where they were
 * put is part of the pack.
 *
 * Offsets, not coordinates — each box keeps its place in the sheet's grid and
 * is nudged from there, so a pack nobody has dragged still prints exactly as it
 * always did. Stored as a share of the SHEET'S WIDTH, because the pack scales
 * with whatever page it is drawn on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->json('box_positions')->nullable()->after('hidden_boxes');
        });
    }

    public function down(): void
    {
        Schema::table('tech_packs', fn (Blueprint $t) => $t->dropColumn('box_positions'));
    }
};
