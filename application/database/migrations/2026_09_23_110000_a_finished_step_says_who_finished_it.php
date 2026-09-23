<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who signed off a step.
 *
 * A Tech Pack records the account officer who passed it on — officer_approved_by
 * — and then nothing at all about the person who gave it its final approval and
 * released production. The floor asked on 2026-09-23 whether the leader had
 * approved IC2026-01237, and the honest answer was that the system did not know:
 * the task said complete at 11:36 and named nobody.
 *
 * Every completed step, not only the pack: the same question is worth answering
 * about a cutting step somebody overrode.
 *
 * Nullable, because nothing before this recorded it and a step finished by the
 * scheduler is finished by nobody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();

            // Whether it was approved or pushed through. An override is a
            // legitimate thing to do and a different thing to have done.
            $table->boolean('was_forced')->default(false)->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('was_forced');
        });
    }
};
