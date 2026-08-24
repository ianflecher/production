<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tech pack gets its own table.
 *
 * Bolting it onto job_orders took that table to within 1,951 bytes of InnoDB's
 * 65,535-byte row limit — close enough that the next handful of fields would be
 * refused by MySQL, at migration time, on the live database. JobOrderRowSizeTest
 * said so, and said the fix is a new table rather than a bigger threshold. It
 * is right: the tech pack is its own document, not more columns on the job order.
 *
 * Only fields the job order does NOT already carry live here. Neck, cuff, print
 * label, packaging, sticker, fabric and print type stay where they are, so
 * nothing ends up with two homes and two answers.
 */
return new class extends Migration
{
    /** What moves out of job_orders, and what it is called on the way in. */
    private const MOVED = [
        'design_name' => 'design_name',
        'fitting' => 'fitting',
        'thread_color' => 'stitch_thread',
        'item_style' => 'item_style',
        'print_tech' => 'print_tech',
        'color_1' => 'color_1',
        'color_2' => 'color_2',
        'color_3' => 'color_3',
        'front_print_placement' => 'front_print_placement',
        'front_actual_size' => 'front_actual_size',
        'back_print_placement' => 'back_print_placement',
        'back_actual_size' => 'back_actual_size',
        'tshirt_color' => 'tshirt_color',
        'cutting_method' => 'cutting_method',
        'size_range' => 'size_range',
        'tag_1_details' => 'tag_1_details',
        'tag_2_details' => 'tag_2_details',
        'folder_shot_path' => 'folder_shot_path',
        'folder_shot_name' => 'folder_shot_name',
        'file_location_notes' => 'file_location_notes',
        'additional_tech_notes' => 'additional_tech_notes',
        'artist_name' => 'artist_name',
    ];

    public function up(): void
    {
        Schema::create('tech_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('design_name', 120)->nullable();
            $table->string('fitting', 60)->nullable();
            $table->string('item_style', 100)->nullable();
            $table->string('print_tech', 60)->nullable();

            $table->string('color_1', 40)->nullable();
            $table->string('color_2', 40)->nullable();
            $table->string('color_3', 40)->nullable();

            $table->string('front_print_placement', 60)->nullable();
            $table->string('front_actual_size', 60)->nullable();
            $table->string('back_print_placement', 60)->nullable();
            $table->string('back_actual_size', 60)->nullable();

            $table->string('tshirt_color', 60)->nullable();
            $table->string('stitch_thread', 60)->nullable();
            $table->string('cutting_method', 60)->nullable();
            $table->string('size_range', 60)->nullable();

            $table->string('tag_1_details', 120)->nullable();
            $table->string('tag_2_details', 120)->nullable();

            $table->string('folder_shot_path', 200)->nullable();
            $table->string('folder_shot_name', 120)->nullable();
            $table->string('file_location_notes', 200)->nullable();
            $table->text('additional_tech_notes')->nullable();
            $table->string('artist_name', 100)->nullable();

            $table->timestamps();
        });

        // Carry across whatever was already typed, so nothing is lost.
        foreach (DB::table('job_orders')->get() as $jo) {
            $row = ['production_order_id' => $jo->production_order_id,
                'created_at' => now(), 'updated_at' => now()];
            $any = false;

            foreach (self::MOVED as $from => $to) {
                if (filled($jo->$from ?? null)) {
                    $row[$to] = $jo->$from;
                    $any = true;
                }
            }

            if ($any) {
                DB::table('tech_packs')->insert($row);
            }
        }

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::MOVED));
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('design_name', 120)->nullable();
            $table->string('fitting', 60)->nullable();
            $table->string('thread_color', 60)->nullable();
            $table->string('item_style', 100)->nullable();
            $table->string('print_tech', 60)->nullable();
            $table->string('color_1', 40)->nullable();
            $table->string('color_2', 40)->nullable();
            $table->string('color_3', 40)->nullable();
            $table->string('front_print_placement', 60)->nullable();
            $table->string('front_actual_size', 60)->nullable();
            $table->string('back_print_placement', 60)->nullable();
            $table->string('back_actual_size', 60)->nullable();
            $table->string('tshirt_color', 60)->nullable();
            $table->string('cutting_method', 60)->nullable();
            $table->string('size_range', 60)->nullable();
            $table->string('tag_1_details', 120)->nullable();
            $table->string('tag_2_details', 120)->nullable();
            $table->string('folder_shot_path', 200)->nullable();
            $table->string('folder_shot_name', 120)->nullable();
            $table->string('file_location_notes', 200)->nullable();
            $table->text('additional_tech_notes')->nullable();
            $table->string('artist_name', 100)->nullable();
        });

        Schema::dropIfExists('tech_packs');
    }
};
