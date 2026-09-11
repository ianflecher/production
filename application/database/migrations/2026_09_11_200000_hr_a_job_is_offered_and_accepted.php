<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage three: the offer, and the person it turns into.
 *
 * Two tables. The offer is what was put to them - the job, the money, when
 * they start - and it is editable until it is sent, because HR writes the
 * scope and the salary themselves. The employee row is what they become once
 * they say yes, and it is the only thing that ties the HR module to a login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_job_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_applicant_id')->constrained('hr_applicants')->cascadeOnDelete();

            $table->string('position');
            // What the job actually is. A template HR edits, not a fixed form.
            $table->text('scope')->nullable();
            $table->decimal('salary', 12, 2)->nullable();
            $table->string('salary_period')->default('monthly');
            $table->date('starts_on')->nullable();
            $table->text('terms')->nullable();

            // draft -> sent -> accepted / declined
            $table->string('status')->default('draft');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('responded_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('hr_employees', function (Blueprint $table) {
            $table->id();

            // The login this person uses. HR data hangs off it rather than
            // being duplicated into it - the shop's user row stays what it is.
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Where they came from. Null for staff who were here before HR
            // existed and get an employee record added by hand later.
            $table->foreignId('hr_applicant_id')->nullable()->constrained('hr_applicants')->nullOnDelete();
            $table->foreignId('hr_job_offer_id')->nullable()->constrained('hr_job_offers')->nullOnDelete();

            $table->string('position')->nullable();
            $table->decimal('salary', 12, 2)->nullable();
            $table->string('salary_period')->default('monthly');
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employees');
        Schema::dropIfExists('hr_job_offers');
    }
};
