<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last seam group on the paper form only fills three of its four columns.
 * The fourth is left blank with a "Sewer:" under it — a spare column to write
 * in whatever this particular garment needed. Give it the same three boxes so
 * it can actually be used instead of printing as dead space.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('extra_seam_label')->nullable();   // what the seam is
            $table->string('extra_seam_note')->nullable();    // the blank line under it
            $table->string('extra_seam_sewer')->nullable();   // who sewed it
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn(['extra_seam_label', 'extra_seam_note', 'extra_seam_sewer']);
        });
    }
};
