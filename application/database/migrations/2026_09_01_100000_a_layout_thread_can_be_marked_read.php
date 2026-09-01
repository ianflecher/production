<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read marks for a layout's thread, not only a job order's.
 *
 * Without this a message sent while the layout is being drawn raised no badge
 * anywhere — not on the row, not in the sidebar — so the artist could be
 * waiting on an answer nobody knew had been asked for.
 *
 * production_order_id stays nullable for these rows. Both MySQL and SQLite
 * treat NULLs in a unique index as distinct, so the existing
 * unique(user_id, production_order_id) does not collide across layout rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_reads', function (Blueprint $table) {
            $table->foreignId('inquiry_id')->nullable()->after('production_order_id')
                ->constrained('inquiries')->cascadeOnDelete();
            $table->unique(['user_id', 'inquiry_id']);
        });

        Schema::table('message_reads', function (Blueprint $table) {
            $table->unsignedBigInteger('production_order_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('message_reads', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'inquiry_id']);
            $table->dropForeign(['inquiry_id']);
            $table->dropColumn('inquiry_id');
        });
    }
};
