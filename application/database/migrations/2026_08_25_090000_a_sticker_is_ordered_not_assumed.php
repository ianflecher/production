<?php

use App\Models\ProductionOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * No sticker until somebody asks for one.
 *
 * The column arrived defaulting to TRUE, so every order wanted a sticker from
 * the moment it existed — before anyone had said a word about it. The step
 * only became honest once the account officer saved the pack, because that is
 * where the answer is worked out from the "Sticker / extra" row.
 *
 * That is backwards for a row that is often left blank: a job with nothing
 * written on that line was asking the supply desk for a sticker nobody had
 * ordered. The rule is that the step follows the row, and a blank row means
 * there is none — so the absence of an answer has to mean no, not yes.
 *
 * Orders already on the system are brought into line with what their own sheet
 * says. Their tasks are left alone: an order in production has a sticker step
 * because somebody built one, and a migration is no place to decide otherwise.
 * The step is reconciled the next time the pack is saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->boolean('needs_sticker')->default(false)->change();
        });

        // What each order's own sheet says, which is the only answer that counts.
        ProductionOrder::query()
            ->with('jobOrder')
            ->chunkById(200, function ($orders) {
                foreach ($orders as $order) {
                    $wanted = ProductionOrder::namesASticker($order->jobOrder?->free_logo_sticker);

                    if ($wanted !== (bool) $order->needs_sticker) {
                        DB::table('production_orders')
                            ->where('id', $order->id)
                            ->update(['needs_sticker' => $wanted]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->boolean('needs_sticker')->default(true)->change();
        });
    }
};
