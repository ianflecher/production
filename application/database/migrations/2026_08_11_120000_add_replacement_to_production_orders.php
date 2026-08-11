<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remakes. A wrong colour, a damaged panel, a seam that failed QC — the shop
 * makes those pieces again, and that remake is a real job that has to go
 * through printing, cutting, sewing and checking like any other.
 *
 * It was being run as an ordinary new order with a note, which meant nobody
 * could tell a remake from a sale: the shop looked busier than it was and the
 * cost of doing work twice was invisible. Pointing it at the order it replaces
 * makes both answerable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('production_orders', 'replaces_order_id')) {
                $table->foreignId('replaces_order_id')->nullable()->after('created_by')
                    ->constrained('production_orders')->nullOnDelete();
            }

            if (! Schema::hasColumn('production_orders', 'replacement_reason')) {
                $table->string('replacement_reason')->nullable()->after('replaces_order_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            if (Schema::hasColumn('production_orders', 'replaces_order_id')) {
                $table->dropConstrainedForeignId('replaces_order_id');
            }

            if (Schema::hasColumn('production_orders', 'replacement_reason')) {
                $table->dropColumn('replacement_reason');
            }
        });
    }
};
