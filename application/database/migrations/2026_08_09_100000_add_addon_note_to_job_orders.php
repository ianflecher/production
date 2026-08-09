<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the add-on actually covers — "sleeves", "left chest", "collar and cuffs".
 *
 * The add-on dropdown says WHICH treatment (sublimated, reflectorized), never
 * WHERE it goes, so the floor had to guess or ring the account officer. Only
 * embroidery had somewhere to write it down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('addon_note', 500)->nullable()->after('addon_other');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('addon_note');
        });
    }
};
