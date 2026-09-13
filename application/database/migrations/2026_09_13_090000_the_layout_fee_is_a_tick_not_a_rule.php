<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The layout fee stops charging itself.
 *
 * It went onto every quotation automatically, which is not how the shop
 * actually sells: some jobs carry it, some do not, and the officer was left
 * discounting ₱500 back off to say so. Now it is a box they tick.
 *
 * Existing orders are ticked ON where the fee was being charged, so nothing
 * already quoted to a client silently drops ₱500 the next time somebody opens
 * it. The waiver rules are unchanged and still apply on top: a job discounted
 * down to nothing does not become a ₱500 layout charge, and 24 pieces still
 * credits it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->boolean('charge_layout_fee')->default(false)->after('rush_fee');
        });

        // What the fee did until today: charged unless the discount already
        // covered the whole job, or the job was never priced.
        DB::table('production_orders')
            ->whereNotNull('unit_price')
            ->update(['charge_layout_fee' => true]);
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn('charge_layout_fee');
        });
    }
};
