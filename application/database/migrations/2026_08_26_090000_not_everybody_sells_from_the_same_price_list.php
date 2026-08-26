<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not everybody sells from the same price list.
 *
 * The merch line is sold flat — a hybrid jersey is 1,450 whether it is five
 * or eighty — off a list of products the standard tables have never carried.
 *
 * Two columns, because they answer two different questions. The one on the
 * account says which list that officer sells from today. The one on the order
 * says which list THIS job was quoted from, written down when it was created,
 * so the figures on a job do not move when somebody else opens it or when the
 * officer is later put on another list. A quotation that changes after it has
 * been given to a client is not a quotation.
 *
 * Null means the standard list, which is what every existing account and
 * every existing order was priced from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('price_list')->nullable()->after('team');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->string('price_list')->nullable()->after('product_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('price_list');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn('price_list');
        });
    }
};
