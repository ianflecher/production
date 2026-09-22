<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The same material, under the name the shop happens to use that day.
 *
 * A job order asks for QA700. The stock sheet calls it QUIANA. They are one
 * fabric, and until now nothing in the system knew it: the desk bridged the gap
 * by hand, picking the right row out of a dropdown of every material in the
 * shop. With the dropdown gone, the gap has to be written down somewhere, so it
 * is written down here.
 *
 * Matched on MaterialName::key(), so spelling, case and punctuation are already
 * noise by the time a pair is looked up — this table is for names that are
 * genuinely different words, not different spellings of one.
 */
return new class extends Migration
{
    /** What the shop already calls by two names. */
    private const KNOWN = [
        ['QA700', 'QUIANA'],
    ];

    public function up(): void
    {
        Schema::create('material_aliases', function (Blueprint $table) {
            $table->id();
            // Two names for one thing. Which is "the" name is not a question
            // worth having an opinion about: a lookup tries both ways round.
            $table->string('alias');
            $table->string('material');
            $table->string('added_by')->nullable();
            $table->timestamps();

            $table->index('alias');
            $table->index('material');
        });

        $now = now();

        DB::table('material_aliases')->insert(array_map(fn ($pair) => [
            'alias' => $pair[0],
            'material' => $pair[1],
            'added_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::KNOWN));
    }

    public function down(): void
    {
        Schema::dropIfExists('material_aliases');
    }
};
