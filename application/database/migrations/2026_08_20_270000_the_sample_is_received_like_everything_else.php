<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nothing enters finished goods without being received — the approved sample
 * included.
 *
 * Approving the sample used to count the piece straight into stock. Stock then
 * said a garment was on the shelf, with a Release button beside it, while the
 * piece was still in somebody's hands on the floor. The sample is queued for
 * the inventory desk now, like every other finished piece.
 *
 * `is_sample` marks that one piece, so a second approval cannot queue another
 * and so the desk can see what it is receiving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_receipts', function (Blueprint $table) {
            $table->boolean('is_sample')->default(false)->after('status');
        });

        // Anything already counted in as a sample: take it back out of stock and
        // queue it, so the desk receives the piece it actually has.
        $stocked = DB::table('product_movements')->where('reason', 'sample')->get();

        foreach ($stocked as $movement) {
            $order = DB::table('production_orders')->find($movement->production_order_id);
            $item = DB::table('product_items')->find($movement->product_item_id);

            if (! $order || ! $item) {
                continue;
            }

            DB::table('product_items')->where('id', $item->id)
                ->update(['quantity' => DB::raw('GREATEST(quantity - '.(float) $movement->quantity.', 0)')]);

            DB::table('product_receipts')->insert([
                'production_order_id' => $order->id,
                'name' => $item->name,
                'unit' => $item->unit ?: 'pcs',
                'expected_quantity' => $movement->quantity,
                'status' => 'pending',
                'is_sample' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('product_movements')->where('reason', 'sample')->delete();
    }

    public function down(): void
    {
        Schema::table('product_receipts', fn (Blueprint $t) => $t->dropColumn('is_sample'));
    }
};
