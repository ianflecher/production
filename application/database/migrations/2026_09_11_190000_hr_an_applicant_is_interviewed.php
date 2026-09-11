<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage two: the interviews.
 *
 * One row per round, up to three. The interviewer is chosen per round rather
 * than fixed to a job title, because who sits in depends on the job: a sewer
 * is seen by the sewing supervisor, an artist by the artist leader, and Boss G
 * sits in on whoever he wants to. Naming the person per round says what
 * actually happened instead of what the rule assumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_applicant_id')->constrained('hr_applicants')->cascadeOnDelete();

            // 1, 2 or 3. The shop does up to three.
            $table->unsignedTinyInteger('round')->default(1);

            $table->foreignId('interviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('scheduled_at')->nullable();

            // pending until it has happened, then passed or failed.
            $table->string('outcome')->default('pending');
            $table->text('notes')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['hr_applicant_id', 'round']);
            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_interviews');
    }
};
