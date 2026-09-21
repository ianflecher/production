<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which garment the sewing record is a record of.
 *
 * The record is now one line per operation — the shop's own sheet, the way it
 * is written on paper — so it has to know which garment's operations to lay
 * out. That is a decision somebody makes once, at the machine, and everybody
 * who opens the job afterwards should see the same list.
 *
 * Nullable: a job sewn before this has a record and no garment against it, and
 * its lines still read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('sewing_garment')->nullable()->after('sewing_log');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('sewing_garment');
        });
    }
};
