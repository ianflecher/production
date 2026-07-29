<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Client-facing documents: DR (delivery receipt, no VAT) and
        // PQ (price quotation / invoice, +12% VAT). Most fields are typed by the
        // account officer, so they live in a flexible JSON blob.
        Schema::create('order_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->string('type', 10);              // dr | pq
            $table->string('number', 50)->nullable();
            $table->json('items')->nullable();       // line items on the sheet
            $table->json('fields')->nullable();      // every other typed field
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['production_order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_documents');
    }
};
