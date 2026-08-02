<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('category');                     // App\Models\Expense::CATEGORIES
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->date('spent_at');                       // the day the money went out
            $table->string('method')->nullable();           // Cash / GCash / Bank Transfer
            $table->string('reference')->nullable();        // OR / invoice number
            // Receipt image or PDF, kept on the private disk like payment proofs.
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // Deleting an expense should not silently rewrite past months.
            $table->softDeletes();

            $table->index('spent_at');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
