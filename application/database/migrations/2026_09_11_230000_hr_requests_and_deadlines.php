<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage five and the rest of six: what people ask for, and what HR must not miss.
 *
 * The five things staff file — leave, a change of schedule, undertime,
 * overtime, official business — are one table with a type, not five tables.
 * They are the same shape: a person, some hours or days, a reason, and
 * somebody deciding. Five tables would be five copies of one approval flow to
 * keep in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_employee_id')->constrained('hr_employees')->cascadeOnDelete();

            // leave / schedule_change / undertime / overtime / official_business
            $table->string('type');

            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            // Only the hour-shaped ones use these: undertime, overtime, and an
            // official business that is half a day.
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();

            $table->text('reason');

            // pending / approved / declined
            $table->string('status')->default('pending');
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['hr_employee_id', 'status']);
            $table->index('starts_on');
        });

        Schema::create('hr_deadlines', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            // payslip / government
            $table->string('kind')->default('payslip');
            $table->date('due_on');
            $table->text('note')->nullable();
            $table->dateTime('done_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['kind', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_deadlines');
        Schema::dropIfExists('hr_requests');
    }
};
