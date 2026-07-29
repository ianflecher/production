<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an order finishes, one pending receipt is queued per finished product.
 * The products desk confirms how many they actually received in person, which
 * is what gets added to stock (product_items).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('unit', 30)->default('pcs');
            $table->decimal('expected_quantity', 12, 2)->default(0);
            $table->decimal('received_quantity', 12, 2)->nullable();
            $table->string('status', 20)->default('pending');   // pending | received
            $table->foreignId('product_item_id')->nullable()->constrained('product_items')->nullOnDelete();
            $table->string('received_by', 100)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_receipts');
    }
};
