<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many pieces get a back pocket (0..quantity). NULL / 0 means none.
     * The per-piece fee still comes from config('pricing.back_pocket_fee').
     */
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->unsignedInteger('back_pocket_qty')->nullable()->after('back_pocket');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn('back_pocket_qty');
        });
    }
};
