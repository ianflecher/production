<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages are a conversation ON a job order, not person-to-person: everyone
 * connected to the order (the account officer, leaders, and whoever is assigned
 * to its tasks) shares one thread.
 *
 * The person-to-person shape shipped earlier the same day and never carried
 * any data, so the table is simply rebuilt rather than migrated column by
 * column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('messages');

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            // The thread this belongs to.
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['production_order_id', 'id']);
        });

        // How far each person has read in each order's thread.
        Schema::create('message_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'production_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reads');
        Schema::dropIfExists('messages');
    }
};
