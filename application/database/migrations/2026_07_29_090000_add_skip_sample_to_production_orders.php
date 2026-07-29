<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            // No physical sample for the client — produce the full order directly
            // (skips the sample production run and its client approval).
            $table->boolean('skip_sample')->default(false)->after('massprod_priority');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn('skip_sample');
        });
    }
};
