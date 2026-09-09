<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A brief can become several orders - one per design.
 *
 * Stephanie Moto asked for five products, 830 pieces, on one enquiry. The link
 * between the two was a single production_order_id on the enquiry, so the shop
 * could write ONE order from a brief and no more: the second attempt bounced
 * back to the first, and had it got through, the enquiry would have forgotten
 * the order it already had. Five products meant five enquiries for one client,
 * which loses the thing the shop was trying to keep - that it is one job for
 * one person, quoted and followed up once.
 *
 * The order now knows which enquiry it came from, and which DESIGN it is for.
 * Five designs, five orders, one brief, one follow-up. A design that has an
 * order says so; a design that does not is still waiting to be written.
 *
 * The enquiry keeps production_order_id, pointing at the first order written
 * from it. Every page that asks "did this enquiry become a job?" still gets
 * its answer from there, and nothing that reads it has to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->foreignId('inquiry_id')->nullable()->after('client_id')
                ->constrained()->nullOnDelete();

            // Which design this order is making. Null for an order written
            // before this existed, or one taken without a brief at all - a
            // walk-in with their own artwork.
            $table->foreignId('inquiry_design_id')->nullable()->after('inquiry_id')
                ->constrained('inquiry_designs')->nullOnDelete();

            $table->index('inquiry_id');
        });

        // What is already linked, said the other way round too, so an enquiry
        // can list its orders from the start rather than only the ones written
        // after today.
        DB::table('inquiries')
            ->whereNotNull('production_order_id')
            ->orderBy('id')
            ->each(function ($inquiry) {
                DB::table('production_orders')
                    ->where('id', $inquiry->production_order_id)
                    ->whereNull('inquiry_id')
                    ->update(['inquiry_id' => $inquiry->id]);
            });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inquiry_design_id');
            $table->dropConstrainedForeignId('inquiry_id');
        });
    }
};
