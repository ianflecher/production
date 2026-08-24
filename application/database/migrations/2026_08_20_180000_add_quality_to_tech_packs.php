<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The grade of blank the job is cut from — "Premium", "Standard" — which the
 * shop reads off the tech pack beside the fabric. Free text, because the
 * grades are named differently by different suppliers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->string('quality', 60)->nullable()->after('item_style');
        });
    }

    public function down(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->dropColumn('quality');
        });
    }
};
