<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A line from a detail box to the place on the garment it describes.
 *
 * A tech pack shows a woven label, a front print and a back print in boxes
 * around the edge, and the floor has to work out which part of the garment each
 * one belongs to. Every printed pack in the trade answers that with a leader
 * line drawn from the box to the spot. This is where the spot is kept.
 *
 * A point on the SHEET, as a share of its width, so the line lands in the same
 * place whatever size the sheet is drawn at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->json('callouts')->nullable()->after('box_positions');
        });
    }

    public function down(): void
    {
        Schema::table('tech_packs', fn (Blueprint $t) => $t->dropColumn('callouts'));
    }
};
