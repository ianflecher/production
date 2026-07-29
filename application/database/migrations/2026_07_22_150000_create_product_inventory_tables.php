<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finished-products inventory — separate from raw materials. Products are
 * stocked automatically when an order finishes, and released when the client
 * receives them. Mirrors the inventory_items / stock_movements pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('unit', 30)->default('pcs');
            $table->decimal('quantity', 12, 2)->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('product_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_item_id')->constrained('product_items')->cascadeOnDelete();
            $table->string('direction', 3);            // in | out
            $table->decimal('quantity', 12, 2);
            $table->decimal('balance_after', 12, 2)->nullable();
            $table->string('reason')->nullable();      // produced | added | restock | released
            $table->string('note')->nullable();
            $table->foreignId('production_order_id')->nullable()->constrained('production_orders')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operator_name', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_movements');
        Schema::dropIfExists('product_items');
    }
};
