<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->string('product_type')->nullable()->after('customer_name');
            $table->boolean('back_pocket')->default(false)->after('cutting_type');
            $table->decimal('unit_price', 10, 2)->nullable()->after('back_pocket');
            $table->decimal('total_price', 10, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn(['product_type', 'back_pocket', 'unit_price', 'total_price']);
        });
    }
};
