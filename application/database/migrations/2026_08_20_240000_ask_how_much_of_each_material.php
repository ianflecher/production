<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much of each material the job actually needs.
 *
 * A material request carried a NAME and nothing else, so the desk could issue
 * any amount and the only check was whether the shelf had it — a hundred blanks
 * went out against an order for fifty-five and nothing objected. And issuing
 * overwrote the request's own quantity column, so afterwards the record simply
 * said a hundred, with no memory that anything else was ever intended.
 *
 * Two numbers, kept apart: what was asked for, and what went out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            // material name => amount, alongside the existing name list so
            // nothing that reads rawMaterialsList() has to change.
            $table->json('raw_material_quantities')->nullable()->after('raw_materials');
        });

        Schema::table('material_requests', function (Blueprint $table) {
            $table->decimal('requested_quantity', 12, 2)->nullable()->after('quantity');
            $table->decimal('issued_quantity', 12, 2)->nullable()->after('requested_quantity');
        });

        // What the old column holds for a settled request is what went out.
        DB::table('material_requests')
            ->where('status', 'approved')
            ->whereNotNull('quantity')
            ->update(['issued_quantity' => DB::raw('quantity')]);
    }

    public function down(): void
    {
        Schema::table('job_orders', fn (Blueprint $t) => $t->dropColumn('raw_material_quantities'));
        Schema::table('material_requests', fn (Blueprint $t) => $t->dropColumn(['requested_quantity', 'issued_quantity']));
    }
};
