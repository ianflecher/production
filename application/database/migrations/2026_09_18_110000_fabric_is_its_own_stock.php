<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fabric is not the same shelf as caps.
 *
 * The raw materials desk holds ready-made stock - shirts, hoodies, caps,
 * boxes, tapes - counted in pieces. The raw materials supervisor holds the
 * fabric: bolts, counted by the kilo, with their own codes and suppliers. All
 * 1,670 rows in this table today are the first kind, and not one is the
 * second, because her stock has only ever lived in a spreadsheet.
 *
 * One column rather than a second table: it is the same act - count it, issue
 * it against a job, log the movement - so every one of those paths keeps
 * working, and the two desks are told apart by a filter rather than by a fork
 * in the code.
 *
 * Everything already here is ready-made, which is what the default says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('kind', 20)->default('ready_made')->after('category');
            $table->index(['kind', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropIndex(['kind', 'name']);
            $table->dropColumn('kind');
        });
    }
};
