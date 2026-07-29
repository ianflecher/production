<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw material stock.
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('unit')->default('pcs');
            $table->decimal('quantity', 12, 2)->default(0);
            $table->timestamps();
        });

        // Materials requested by an order's job order — the raw-materials
        // account approves (deducts stock) or rejects each line.
        Schema::create('material_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->string('material');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->decimal('quantity', 12, 2)->nullable(); // issued qty on approval
            $table->string('note')->nullable();             // reject reason / remarks
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['production_order_id', 'material']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_requests');
        Schema::dropIfExists('inventory_items');
    }
};
