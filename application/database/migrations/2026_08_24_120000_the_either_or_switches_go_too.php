<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two switches the either/or rows were driven by.
 *
 * Print label, neck label, t-shirt colour and thread colour are four rows of
 * their own now, so nothing asks which of a pair the sheet is showing. The
 * columns went on being written on every save and read by nobody, which reads
 * to the next person like a setting that still means something.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->dropColumn(['label_type', 'color_type']);
        });
    }

    public function down(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->string('label_type', 30)->nullable()->after('size_range');
            $table->string('color_type', 30)->nullable()->after('label_type');
        });
    }
};
