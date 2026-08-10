<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sewing block on the paper job order asks for far more than we stored.
 * Every seam group names a sewer and a thread, and the neck/cuff carry a size
 * — all of it was being written on the printout by hand and lost.
 */
return new class extends Migration
{
    /** column => the paper form's label, for anyone reading this later. */
    private const FIELDS = [
        'neck_size' => 'Neck — Size',
        'cuff_size' => 'Cuff / Arm Sleeves — Size',
        'neck_label_thread' => 'Neck Label — Thread Colour',
        'bottom_hem_thread' => 'Bottom Hem — Thread Colour',

        'neckbond_sewer' => 'Neckbond Shoulder — Sewer',
        'neckbond_thread' => 'Neckbond Shoulder — Thread Code/Colour',
        'hangtag_woven_sewer' => 'Top/Neck/Hangtag Woven — Sewer',
        'hangtag_woven_thread' => 'Top/Neck/Hangtag Woven — Thread Code/Colour',
        'flatbed_sewer' => 'Flatbed — Sewer',
        'flatbed_thread' => 'Flatbed — Thread Code/Colour',
        'close_side_sewer' => 'Close Side Body & Sleeve — Sewer',
        'close_side_thread' => 'Close Side Body & Sleeve — Thread Colour',

        'attached_sleeve_sewer' => 'Attached Sleeve / Cuffs — Sewer',
        'attached_sleeve_thread' => 'Attached Sleeve / Cuffs — Thread Colour',
        'topping_side_sewer' => 'Topping Side / Sleeve — Sewer',
        'topping_side_thread' => 'Topping Side / Sleeve — Thread Colour',
        'pipping_sewer' => 'Pipping — Sewer',
        'pipping_thread' => 'Pipping — Thread Colour',

        'sewer_notes' => 'Notes from Sewer',
    ];

    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            foreach (array_keys(self::FIELDS) as $column) {
                if ($column === 'sewer_notes') {
                    $table->text($column)->nullable();

                    continue;
                }

                $table->string($column)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::FIELDS));
        });
    }
};
