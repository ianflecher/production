<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The × takes the BOX away, not just the picture in it.
 *
 * It already did for the sample panel, whose boxes are a list. Everywhere else
 * — the two tags, the two artwork panels, the file-location shot — the box is
 * part of the sheet, so removing one emptied it and drew it again: the artist
 * pressed × and nothing appeared to happen.
 *
 * A job with one woven tag should print one tag, not one tag and an empty
 * square, so the boxes that are not in a list get a list of their own: the ones
 * this pack does NOT want.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->json('hidden_boxes')->nullable()->after('image_sizes');
        });
    }

    public function down(): void
    {
        Schema::table('tech_packs', fn (Blueprint $t) => $t->dropColumn('hidden_boxes'));
    }
};
