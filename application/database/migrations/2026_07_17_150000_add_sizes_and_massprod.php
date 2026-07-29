<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Size breakdown taken at first inquiry: how many of each size.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->string('size', 10);
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['production_order_id', 'size']);
        });

        Schema::table('production_orders', function (Blueprint $table) {
            // MASSPROD PRIORITY = skip the printed 1-pc sample, go straight to
            // mass production.
            $table->boolean('massprod_priority')->default(false)->after('cutting_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn('massprod_priority');
        });
    }
};
