<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A sample pack and a mass production pack.
 *
 * The shop drew one tech pack per order, and the sample and the batch were the
 * same sheet. They are not the same garment: the sample is the one the client
 * holds and asks to change, and the batch is what those changes turned into.
 * Written on one sheet, approving the sample meant overwriting the only record
 * of what was approved.
 *
 * The phase joins the order in what makes a pack its own row, so each phase is
 * drawn, approved and printed on its own.
 *
 * Everything already drawn is a sample pack: that is the sheet the shop has
 * been filling in, and the batch sheet does not exist until the sample is
 * approved and copied forward.
 *
 * The order's foreign key rests on the old unique index, being its only
 * column, and MySQL will not drop the floor out from under a key — errno 1553.
 * The key gets an index of its own first, and then the swap is allowed. This
 * is the same trap the material request size hit; it is written out here so
 * the next person widening a unique looks for its foreign key first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tech_packs', 'phase')) {
            Schema::table('tech_packs', function (Blueprint $table) {
                $table->string('phase', 12)->default('sample')->after('production_order_id');
            });
        }

        Schema::table('tech_packs', function (Blueprint $table) {
            $table->index('production_order_id', 'tech_packs_production_order_id_index');
        });

        Schema::table('tech_packs', function (Blueprint $table) {
            $table->dropUnique('tech_packs_production_order_id_unique');
            $table->unique(['production_order_id', 'phase']);
        });
    }

    public function down(): void
    {
        // One order may only have one pack again, so the batch sheets go first
        // — otherwise restoring the old unique fails on the orders that have
        // both. The sample is the one kept: it is the sheet that was there
        // before this migration, and the batch was copied from it.
        DB::table('tech_packs')->where('phase', 'massprod')->delete();

        Schema::table('tech_packs', function (Blueprint $table) {
            $table->dropUnique('tech_packs_production_order_id_phase_unique');
            $table->unique('production_order_id', 'tech_packs_production_order_id_unique');
        });

        Schema::table('tech_packs', function (Blueprint $table) {
            $table->dropIndex('tech_packs_production_order_id_index');
        });

        Schema::table('tech_packs', fn (Blueprint $t) => $t->dropColumn('phase'));
    }
};
