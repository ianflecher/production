<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Errors somebody has looked at and dealt with.
 *
 * The errors page reads the log file, which cannot be edited safely and should
 * not be — it is the record. So dismissing one is remembered here instead, by a
 * fingerprint of the failure and the moment it was cleared.
 *
 * Cleared, not deleted: if the same thing fails again afterwards it comes back,
 * because the new occurrence is later than the dismissal. A page that lets you
 * silence a problem for good is worse than no page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dismissed_errors', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->timestamp('dismissed_at');
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dismissed_errors');
    }
};
