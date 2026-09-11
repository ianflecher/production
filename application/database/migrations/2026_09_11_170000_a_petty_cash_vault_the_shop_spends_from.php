<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money put INTO the petty cash tin.
 *
 * Only the top-ups are a table. What comes out is already written down: an
 * expense paid with "Petty cash" IS the withdrawal, so the balance is what was
 * put in less what was spent from it. Keeping a second row per spend would
 * mean two records of one event that can drift apart - delete the expense and
 * the tin would still believe the money had gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_topups', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 12, 2);
            $table->string('note')->nullable();
            $table->date('occurred_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_topups');
    }
};
