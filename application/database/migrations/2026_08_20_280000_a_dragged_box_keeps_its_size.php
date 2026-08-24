<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A picture box that was dragged bigger stays bigger.
 *
 * The boxes are resizable by the browser — drag the corner and the panel grows.
 * Nothing recorded it, so the next page load drew every box at its stylesheet
 * size again and the artist's sizing was gone. Whoever sized a back print to
 * match the garment did it once per visit, for nothing.
 *
 * Stored as a share of the SHEET'S OWN WIDTH rather than in pixels: the pack
 * scales with the page it is drawn on, so a box pinned to 240px would be right
 * on one screen and wrong on the next.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->json('image_sizes')->nullable()->after('image_boxes');
        });
    }

    public function down(): void
    {
        Schema::table('tech_packs', fn (Blueprint $t) => $t->dropColumn('image_sizes'));
    }
};
