<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the office asked for:
 *
 *  - clients get a surname of their own, so the list sorts by family name
 *    instead of by whatever was typed first
 *  - an order can be marked RUSH, with the fee that was agreed for it
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('last_name')->nullable()->after('name');
            // Sorting the client list is the whole point of holding it apart.
            $table->index(['last_name', 'name']);
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->boolean('rush')->default(false)->after('massprod_priority');
            $table->decimal('rush_fee', 12, 2)->nullable()->after('rush');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['last_name', 'name']);
            $table->dropColumn('last_name');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn(['rush', 'rush_fee']);
        });
    }
};
