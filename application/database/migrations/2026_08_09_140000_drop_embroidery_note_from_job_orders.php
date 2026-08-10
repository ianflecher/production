<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The embroidery note is gone from the app — the add-on note replaced it, and
 * asked the same question for every add-on rather than only that one.
 *
 * The column was left behind when the field was removed, in case job orders
 * already carried notes worth keeping. None do, so it goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('embroidery_note');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->text('embroidery_note')->nullable()->after('needs_embroidery');
        });
    }
};
