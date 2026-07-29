<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who is on which printer, and why they came off it (shift change, break…).
        Schema::create('printer_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('printer', 50);                 // key from JobOrder::PRINTERS
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('production_order_id')->nullable()->constrained('production_orders')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 20)->nullable();   // break | shift_change | done
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['printer', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_sessions');
    }
};
