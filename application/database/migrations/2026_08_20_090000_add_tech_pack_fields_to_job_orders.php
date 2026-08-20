<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tech pack replaces the job order sheet and the mockup page, and it prints
 * a few things nothing recorded: what the design is called, how it fits, the
 * thread colour, and the actual printed size of each placement.
 *
 * Column widths are deliberate rather than a reflexive varchar(255). InnoDB
 * refuses a row whose columns could exceed 65,535 bytes and a varchar(255) in
 * utf8mb4 reserves 1,020 of them whether or not anything is stored — this table
 * is already two thirds of the way there. See JobOrderRowSizeTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            // What the artwork is called, as the shop refers to it.
            $table->string('design_name', 120)->nullable()->after('status');
            $table->string('fitting', 60)->nullable()->after('design_name');

            // Garment spec the sheet never carried.
            $table->string('thread_color', 60)->nullable()->after('bottom_hem_thread');
            $table->string('zipper_type', 60)->nullable()->after('thread_color');
            $table->string('bp_pocket_color', 60)->nullable()->after('zipper_type');

            // The colourways the design is printed in — "Black, White, Accent".
            $table->string('colorways', 200)->nullable()->after('bp_pocket_color');

            // Where the print goes and how big it actually is:
            // [{"label": "Back", "width": "14.0", "height": "10.688"}, …]
            // A list rather than columns, because a garment can carry one
            // placement or five and the sheet has to print whatever it has.
            $table->text('print_placements')->nullable()->after('colorways');

            // Optional picture of the export folder. The paths below it are the
            // truth; this is what the shop is used to seeing.
            $table->string('folder_shot_path', 200)->nullable()->after('print_placements');
            $table->string('folder_shot_name', 120)->nullable()->after('folder_shot_path');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn([
                'design_name', 'fitting', 'thread_color', 'zipper_type',
                'bp_pocket_color', 'colorways', 'print_placements',
                'folder_shot_path', 'folder_shot_name',
            ]);
        });
    }
};
