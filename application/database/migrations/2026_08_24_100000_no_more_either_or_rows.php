<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The either/or rows become plain ones.
 *
 * Two rows on the materials list were dropdowns that changed what the row was
 * CALLED: one row that said either "Print label" or "Neck label", another that
 * said either "T-shirt color" or "Thread color". A garment can want both, and
 * choosing one hid the other — so the sheet could not say a shirt was black
 * with white thread.
 *
 * Both now exist as rows of their own, which needs somewhere to keep the second
 * answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->string('print_label', 120)->nullable()->after('tshirt_color');
            $table->string('thread_color', 60)->nullable()->after('print_label');
        });
    }

    public function down(): void
    {
        Schema::table('tech_packs', fn (Blueprint $t) => $t->dropColumn(['print_label', 'thread_color']));
    }
};
