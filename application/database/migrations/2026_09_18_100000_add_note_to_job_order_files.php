<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the officer said when they sent the files.
 *
 * A photo of a logo with no word attached is a photo the artist has to guess
 * at - is this the logo, the placement, the colour, the thing to avoid? The
 * message is carried on the files themselves so it arrives with them and
 * stays with them, rather than overwriting the one note the job order has.
 *
 * Every file in one upload gets the same note: one message, one batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_order_files', function (Blueprint $table) {
            $table->text('note')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('job_order_files', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
