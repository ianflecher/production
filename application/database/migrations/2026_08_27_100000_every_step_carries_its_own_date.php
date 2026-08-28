<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A date on every step, not just on the order.
 *
 * The order has a due date and the floor has sixteen steps to reach it, so
 * "due the 14th" told a sewer nothing about whether they were late. The span
 * from the confirmed downpayment to the due date is shared out across the
 * steps, and each one carries the date it has to be finished by.
 *
 * Nullable, because a step only gets a date once the money is confirmed —
 * before that there is no start to measure from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('due_at')->nullable()->after('released_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('due_at');
        });
    }
};
