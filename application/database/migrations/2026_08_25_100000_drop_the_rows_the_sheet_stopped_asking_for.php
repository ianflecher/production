<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Five answers the sheet stopped asking for.
 *
 * Two print placements, two printed sizes and a notes box. Every save
 * validated them and wrote them to the table, and no copy of the pack drew
 * them — there was no box to type one into and no row that printed one.
 *
 * The placements are what the leader lines say now: a red line from the
 * front-artwork box to the chest IS "front print placement: left chest", and
 * saying it twice is how the two come to disagree. The notes box was replaced
 * by the notes an artist drags onto the sheet. Nothing replaced the printed
 * sizes — the shop decided to do without them.
 *
 * A column nobody can fill and nobody can read is worse than no column: it
 * reads like something that works.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $gone = [
        'front_print_placement', 'front_actual_size',
        'back_print_placement', 'back_actual_size',
        'additional_tech_notes',
    ];

    public function up(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            // Only what is actually there: a pack built before some of these
            // existed must not stop the migration.
            $table->dropColumn(array_values(array_filter(
                $this->gone,
                fn ($column) => Schema::hasColumn('tech_packs', $column)
            )));
        });
    }

    public function down(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->string('front_print_placement', 60)->nullable();
            $table->string('front_actual_size', 60)->nullable();
            $table->string('back_print_placement', 60)->nullable();
            $table->string('back_actual_size', 60)->nullable();
            $table->text('additional_tech_notes')->nullable();
        });
    }
};
