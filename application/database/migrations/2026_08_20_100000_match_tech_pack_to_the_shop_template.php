<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Match the tech pack to the shop's own fillable template.
 *
 * The first pass was built from a screenshot and guessed at some of it. The
 * template itself turned out to be a PDF form whose 31 field names are the
 * actual spec — so the guesses go and the real fields land, named the same way
 * the shop names them.
 *
 * Out: zipper_type and bp_pocket_color (not on the template at all), colorways
 * as one comma string (it is three named swatches), and print_placements as a
 * free list (it is exactly a front and a back).
 *
 * Widths stay deliberately tight — this table is near InnoDB's row limit and
 * a varchar(255) in utf8mb4 reserves 1,020 bytes whether or not it holds
 * anything. See JobOrderRowSizeTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn(['zipper_type', 'bp_pocket_color', 'colorways', 'print_placements']);
        });

        Schema::table('job_orders', function (Blueprint $table) {
            // Header
            $table->string('item_style', 100)->nullable()->after('fitting');
            $table->string('print_tech', 60)->nullable()->after('item_style');

            // The three colourway swatches, named rather than a comma string.
            $table->string('color_1', 40)->nullable()->after('print_tech');
            $table->string('color_2', 40)->nullable()->after('color_1');
            $table->string('color_3', 40)->nullable()->after('color_2');

            // Exactly a front and a back, each with the size it prints at.
            $table->string('front_print_placement', 60)->nullable()->after('color_3');
            $table->string('front_actual_size', 60)->nullable()->after('front_print_placement');
            $table->string('back_print_placement', 60)->nullable()->after('front_actual_size');
            $table->string('back_actual_size', 60)->nullable()->after('back_print_placement');

            // Materials and components, as the template lists them.
            $table->string('tshirt_color', 60)->nullable()->after('thread_color');
            $table->string('stitch_thread', 60)->nullable()->after('tshirt_color');
            $table->string('cutting_method', 60)->nullable()->after('stitch_thread');
            $table->string('size_range', 60)->nullable()->after('cutting_method');

            // The two woven-tag placements printed under the materials table.
            $table->string('tag_1_details', 120)->nullable()->after('size_range');
            $table->string('tag_2_details', 120)->nullable()->after('tag_1_details');

            // Free notes at the foot, and who drew it — "Dave CAD Mick" is two
            // people, so it is typed rather than read off the assignee.
            $table->string('file_location_notes', 200)->nullable()->after('folder_shot_name');
            $table->text('additional_tech_notes')->nullable()->after('file_location_notes');
            $table->string('artist_name', 100)->nullable()->after('additional_tech_notes');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn([
                'item_style', 'print_tech', 'color_1', 'color_2', 'color_3',
                'front_print_placement', 'front_actual_size',
                'back_print_placement', 'back_actual_size',
                'tshirt_color', 'stitch_thread', 'cutting_method', 'size_range',
                'tag_1_details', 'tag_2_details',
                'file_location_notes', 'additional_tech_notes', 'artist_name',
            ]);
            $table->string('zipper_type', 60)->nullable();
            $table->string('bp_pocket_color', 60)->nullable();
            $table->string('colorways', 200)->nullable();
            $table->text('print_placements')->nullable();
        });
    }
};
