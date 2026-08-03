<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            // Either a typed message, an attached job order, or both.
            $table->text('body')->nullable();
            $table->foreignId('production_order_id')->nullable()
                ->constrained('production_orders')->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Pulling one conversation, newest first, and counting unread.
            $table->index(['sender_id', 'recipient_id']);
            $table->index(['recipient_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
