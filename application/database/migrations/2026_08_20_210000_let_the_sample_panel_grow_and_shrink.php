<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many picture boxes the sample panel has.
 *
 * Four was a guess. A polo with a placket detail wants five; a plain tee wants
 * two, and the two empty boxes underneath it printed as two empty boxes. The
 * artist adds and removes them, so the list of boxes is theirs to keep — null
 * means nobody has changed it and the standard four stand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->json('image_boxes')->nullable()->after('image_uploads');
        });
    }

    public function down(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->dropColumn('image_boxes');
        });
    }
};
