<?php

use App\Models\InventoryItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A material request knows which shelf it is asking from.
 *
 * Two desks hold raw materials. The supervisor holds the fabric, by the kilo;
 * the raw materials desk holds the ready-made stock - the caps, the boxes, the
 * tapes - by the piece. One queue for both meant each of them reading past the
 * other's work to find their own.
 *
 * Which shelf is not something the system can guess from the name: of the
 * materials asked for today, "QA700" matches nothing in either list and
 * "COTTON HOODIE" matches the ready-made one. So the officer says, on the
 * job order, and the request carries the answer.
 *
 * Backfilled by the only evidence there is: a name that matches something on
 * the ready-made shelf is ready-made, and everything else is fabric, which is
 * what the shop says the standing queue is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->json('raw_material_kinds')->nullable()->after('raw_material_quantities');
        });

        Schema::table('material_requests', function (Blueprint $table) {
            $table->string('kind', 20)->default(InventoryItem::KIND_FABRIC)->after('material');
            $table->index(['kind', 'status']);
        });

        $readyMade = DB::table('inventory_items')
            ->where('kind', InventoryItem::KIND_READY_MADE)
            ->pluck('name');

        foreach (DB::table('material_requests')->select('id', 'material')->get() as $request) {
            $isReadyMade = $readyMade->contains(
                fn ($name) => str_contains(strtoupper($name), strtoupper(trim($request->material)))
            );

            if ($isReadyMade) {
                DB::table('material_requests')
                    ->where('id', $request->id)
                    ->update(['kind' => InventoryItem::KIND_READY_MADE]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropIndex(['kind', 'status']);
            $table->dropColumn('kind');
        });

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('raw_material_kinds');
        });
    }
};
