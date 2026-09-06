<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sample has a deadline of its own.
 *
 * The order carried one due date, and it was the date the finished batch had
 * to be out of the door. The sample had no date at all, so a sample that sat a
 * week was only noticed when the batch behind it ran short — the whole delay
 * landed on the floor at the end, where there is no time left to absorb it.
 *
 * Three days from the confirmed payment, four for a riding jersey, which takes
 * a panelled cut and a longer press. The batch keeps the order's own due date:
 * that promise to the client has not changed, and this is the sample's promise
 * to the shop.
 *
 * Nullable: an order with no confirmed payment has not started its clock, and
 * an order that skips the sample never had one to be late for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->date('sample_due_date')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', fn (Blueprint $t) => $t->dropColumn('sample_due_date'));
    }
};
