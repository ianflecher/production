<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A material is asked for one size at a time.
 *
 * The desk got one request per material carrying the whole job's amount, so a
 * run of fifty-five across S to XL arrived as a single line: issue it all, or
 * issue nothing. But the shelf does not run out evenly — the mediums go first
 * — and there was no way to say the larges are out while the smalls go ahead.
 * The order sat whole behind its shortest size.
 *
 * The size joins the material in what makes a request its own row, so each one
 * is issued, or refused, on its own.
 *
 * Empty means no size: an order with no size breakdown still raises the single
 * line it always did. It is a blank string rather than NULL so the unique index
 * keeps holding — MySQL counts two NULLs as different and would let a duplicate
 * through.
 *
 * The order's foreign key leans on the old unique index for support, being its
 * leftmost column, so dropping that index straight off is refused with errno
 * 1553. The key gets an index of its own first, and then the swap is allowed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('material_requests', 'size')) {
            Schema::table('material_requests', function (Blueprint $table) {
                $table->string('size', 20)->default('')->after('material');
            });
        }

        Schema::table('material_requests', function (Blueprint $table) {
            $table->index('production_order_id', 'material_requests_production_order_id_index');
        });

        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropUnique('material_requests_production_order_id_material_unique');
            $table->unique(['production_order_id', 'material', 'size']);
        });
    }

    public function down(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropUnique('material_requests_production_order_id_material_size_unique');
            $table->unique(['production_order_id', 'material']);
        });

        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropIndex('material_requests_production_order_id_index');
        });

        Schema::table('material_requests', fn (Blueprint $t) => $t->dropColumn('size'));
    }
};
