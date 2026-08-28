<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a step came from, so it can go back.
 *
 * When an artist signs off, the work they had not started passes to whoever is
 * still at a desk. That is a loan, not a handover: when they sign back in it
 * returns to them — unless the artist who took it has started it or finished
 * it, in which case it stays where the work is.
 *
 * Null for a step nobody has passed on, which is nearly all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('passed_from')->nullable()->after('assigned_to')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('passed_from');
        });
    }
};
