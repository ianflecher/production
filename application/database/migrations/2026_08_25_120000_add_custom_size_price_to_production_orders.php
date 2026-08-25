<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A price for the pieces the price list does not cover.
 *
 * CS (custom size) and a typed "other size" such as "Kids 8" are not on the
 * size chart, so no tier price applies to them — but the rest of the order
 * still has one. Before this, entering a custom price threw the tier price
 * away for EVERY piece; now the charted sizes keep their automatic price and
 * only the off-chart pieces are priced by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->decimal('custom_size_price', 10, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn('custom_size_price');
        });
    }
};
